<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
use OCA\ProofingGallery\Dto\PublicGalleryPage;
use OCA\ProofingGallery\Domain\MediaSort;
use OCA\ProofingGallery\Domain\PublicPagePagination;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\ICache;
use OCP\ICacheFactory;

final class FolderPublicPageReader {
	private const ORDER_TTL = 300;
	private readonly ICache $orders;

	public function __construct(private MediaIndexService $mediaIndex, private MediaMetadataService $metadata, private MediaTypePolicy $mediaTypes, private PolicyService $policies, ICacheFactory $cacheFactory) {
		$this->orders = $cacheFactory->createDistributed('proofing_gallery_order');
	}

	public function read(PublicShareContext $context, PublicGalleryQuery $query): PublicGalleryPage {
		$gallery = $context->gallery;
		$path = $query->path;
		$multiRoot = $context->scope->multiRoot;
		$scopedRoot = $context->root;
		$groupDepth = max(1, min(8, $context->link->getGroupDepth() ?: $context->settings->navigation->groupDepth));

		$eventAlbums = $gallery->getDeliveryMode() === 'event' && $path === '' && $multiRoot;
		$rootDetails = $gallery->getDeliveryMode() === 'event' && $multiRoot ? $context->scope->details : [];
		if ($eventAlbums) {
			$nodes = [];
			$eventRoots = [];
			foreach ($rootDetails as $detail) {
				try { $node = $scopedRoot->get($detail['path']); } catch (\Throwable) { continue; }
				if (!$node instanceof Folder || !$node->isReadable() || !$scopedRoot->isSubNode($node)) continue;
				$nodes[] = $node;
				$eventRoots[$node->getId()] = $detail;
			}
			$ordered = $this->orderNodes($context, $query, $nodes, true, $eventRoots);
			return $this->page($context, $query, self::entries($ordered), self::nodeMap($ordered), $eventRoots, true, $rootDetails, $groupDepth)
				?? throw new \LogicException('Gallery page unavailable');
		}

		// The sorted order is cached per folder etag, so scrolling through many batches
		// does not list and sort the whole folder again for every request.
		$currentFolder = $this->folderAt($scopedRoot, $path);
		$key = $this->orderKey($context, $query, $currentFolder, $groupDepth);
		$cached = $this->orders->get($key);
		if (is_array($cached)) {
			$page = $this->page($context, $query, $cached, fn (array $entry): ?Node => $this->childNode($currentFolder, $entry), [], false, $rootDetails, $groupDepth);
			if ($page !== null) return $page;
		}

		$nodes = array_values(array_filter(
			$currentFolder->getDirectoryListing(),
			fn (Node $node): bool => $node->isReadable() && !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->mediaTypes->supports($node)))
				&& (!$multiRoot || $context->scope->visiblePath(ltrim($path . '/' . $node->getName(), '/'))),
		));
		$ordered = $this->orderNodes($context, $query, $nodes, false, []);
		$entries = self::entries($ordered);
		$this->orders->set($key, $entries, self::ORDER_TTL);
		return $this->page($context, $query, $entries, self::nodeMap($ordered), [], false, $rootDetails, $groupDepth)
			?? throw new \LogicException('Gallery page unavailable');
	}

	/**
	 * @param list<Node> $nodes
	 * @param array<int, array<string, mixed>> $eventRoots
	 * @return list<Node>
	 */
	private function orderNodes(PublicShareContext $context, PublicGalleryQuery $query, array $nodes, bool $eventAlbums, array $eventRoots): array {
		$foldersVisible = $context->scope->multiRoot || $context->settings->navigation->folders;
		$search = $query->search;
		$sortBy = $query->sortBy;
		$sortDirection = $query->sortDirection;
		$groupBy = $query->groupBy;
		$nodes = array_values(array_filter(
			$nodes,
			static fn (Node $node): bool => ($foldersVisible || !($node instanceof Folder))
				&& ($search === '' || mb_stripos($node->getName(), $search) !== false),
		));
		$captureById = [];
		if ($sortBy === 'capturedAt') foreach ($nodes as $node) {
			if ($node instanceof File) $captureById[$node->getId()] = $this->metadata->summary($node)['capturedAt'] ?? null;
		}
		usort($nodes, static function (Node $left, Node $right) use ($sortBy, $sortDirection, $groupBy, $eventAlbums, $eventRoots, $captureById): int {
			if ($eventAlbums) {
				$order = ['shared' => 0, 'group' => 1, 'private' => 2];
				$roleOrder = ($order[$eventRoots[$left->getId()]['role'] ?? 'shared'] ?? 3) <=> ($order[$eventRoots[$right->getId()]['role'] ?? 'shared'] ?? 3);
				if ($roleOrder !== 0) return $roleOrder;
			}
			if ($groupBy === 'type') {
				$group = self::group($left) <=> self::group($right);
				if ($group !== 0) return $group;
			}
			$folderOrder = ($left instanceof Folder ? 0 : 1) <=> ($right instanceof Folder ? 0 : 1);
			if ($folderOrder !== 0) return $folderOrder;
			$value = static fn (Node $node): array => [
				'id' => $node->getId(), 'name' => $node->getName(), 'size' => $node->getSize(), 'modifiedAt' => $node->getMTime(),
				'capturedAt' => $captureById[$node->getId()] ?? null,
			];
			return MediaSort::compare($value($left), $value($right), $sortBy, $sortDirection);
		});
		return $nodes;
	}

	/**
	 * @param list<array{id: int, name: string, folder: bool, group: string}> $entries
	 * @param callable(array{id: int, name: string, folder: bool, group: string}): ?Node $nodeFor
	 * @param array<int, array<string, mixed>> $eventRoots
	 * @param list<array<string, mixed>> $rootDetails
	 */
	private function page(PublicShareContext $context, PublicGalleryQuery $query, array $entries, callable $nodeFor, array $eventRoots, bool $eventAlbums, array $rootDetails, int $groupDepth): ?PublicGalleryPage {
		$gallery = $context->gallery;
		$settings = $context->settings;
		$focusIndex = null;
		if ($query->focusId !== null) {
			$position = array_search($query->focusId, array_column($entries, 'id'), true);
			if ($position === false) throw new \OCP\Files\NotFoundException('Gallery media not found');
			$focusIndex = $position;
		}
		$offset = PublicPagePagination::offset(count($entries), $query->limit, $query->offset, $focusIndex);

		$nodes = [];
		foreach (array_slice($entries, $offset, $query->limit) as $entry) {
			$node = $nodeFor($entry);
			if ($node === null) return null;
			$nodes[] = $node;
		}
		$items = array_map(function (Node $node) use ($settings, $gallery, $eventAlbums, $eventRoots): array {
			$eventRoot = $eventRoots[$node->getId()] ?? null;
			$item = [
				'id' => $node->getId(),
				'name' => $node->getName(),
				'mimeType' => $node->getMimeType(),
				'size' => (int)$node->getSize(),
				'modifiedAt' => $node->getMTime(),
				'etag' => $node->getEtag(),
				'folder' => $node instanceof Folder,
				'group' => self::group($node),
				'metadata' => $node instanceof File
					? $this->metadata->publicSummary($node, $settings->metadata->publicFields)
					: ['state' => 'unavailable'],
			];
			if ($eventRoot !== null) $item['relativePath'] = $eventRoot['path'];
			if ($eventAlbums && $node instanceof Folder) {
				$item['album'] = $this->eventAlbumSummary($gallery, $node, (string)$eventRoot['path'], (string)$eventRoot['role']);
			}
			return $node instanceof File ? [...$item, ...$this->metadata->publicGeometry($node)] : $item;
		}, $nodes);

		$groups = [];
		$mediaTotal = $mediaOffset = 0;
		foreach ($entries as $index => $entry) {
			if (!$entry['folder']) {
				$mediaTotal++;
				if ($index < $offset) $mediaOffset++;
			}
			$key = $query->groupBy === 'none' ? 'all' : $entry['group'];
			$groups[$key] = ($groups[$key] ?? 0) + 1;
		}
		return new PublicGalleryPage($items, count($entries), $query, $offset,
			nextCursor: null, previousCursor: null, groups: $groups,
			indexState: ['indexed' => count($entries), 'limit' => $this->policies->get('maxIndexedMedia'), 'limitReached' => false, 'complete' => true], scope: ['startPath' => $context->scope->startPath, 'allowedRoots' => $context->scope->roots, 'roots' => $rootDetails, 'viewMode' => 'folder', 'groupDepth' => $groupDepth], focusIndex: $focusIndex, mediaTotal: $mediaTotal, mediaOffset: $mediaOffset,
		);
	}

	/** Cache key: any change to the folder listing, scope, sort or gallery revision starts a new order. */
	private function orderKey(PublicShareContext $context, PublicGalleryQuery $query, Folder $folder, int $groupDepth): string {
		return hash('sha256', json_encode([
			$context->gallery->getId(), $context->gallery->getRevision(), $context->link->getId(), $context->root->getId(),
			$context->scope->multiRoot, $context->scope->roots, $query->path, $query->search, $query->sortBy, $query->sortDirection, $query->groupBy,
			$groupDepth, $context->settings->navigation->folders, $folder->getId(), $folder->getEtag(),
		], JSON_THROW_ON_ERROR));
	}

	/**
	 * @param list<Node> $nodes
	 * @return list<array{id: int, name: string, folder: bool, group: string}>
	 */
	private static function entries(array $nodes): array {
		$entries = [];
		foreach ($nodes as $node) {
			$entries[] = ['id' => $node->getId(), 'name' => $node->getName(), 'folder' => $node instanceof Folder, 'group' => self::group($node)];
		}
		return $entries;
	}

	/** @param list<Node> $nodes @return callable(array{id: int, name: string, folder: bool, group: string}): ?Node */
	private static function nodeMap(array $nodes): callable {
		$byId = [];
		foreach ($nodes as $node) $byId[$node->getId()] = $node;
		return static fn (array $entry): ?Node => $byId[$entry['id']] ?? null;
	}

	/** @param array{id: int, name: string, folder: bool, group: string} $entry */
	private function childNode(Folder $folder, array $entry): ?Node {
		try {
			$node = $folder->get($entry['name']);
		} catch (\Throwable) {
			return null;
		}
		return $node->getId() === $entry['id'] && $node->isReadable() ? $node : null;
	}

	/** @return array{role: string, mediaCount: int, folderCount: int, covers: list<array{id: int, name: string, mimeType: string, etag: string}>} */
	private function eventAlbumSummary(Gallery $gallery, Folder $folder, string $path, string $role): array {
		$page = $this->mediaIndex->page($gallery, 3, null, $path, '', 'name', 'asc');
		$covers = [];
		foreach ($page['items'] as $item) {
			if (count($covers) >= 3) break;
			$covers[] = [
				'id' => (int)$item['id'], 'name' => (string)$item['name'],
				'mimeType' => (string)$item['mimeType'], 'etag' => (string)$item['etag'],
			];
		}
		$mediaCount = (int)$page['total'];
		if ($mediaCount === 0) {
			$fallback = $this->unindexedAlbumMedia($folder, $this->policies->get('maxIndexedMedia'));
			$mediaCount = $fallback['count'];
			$covers = $fallback['covers'];
		}
		$folderCount = count(array_filter(
			$folder->getDirectoryListing(),
			static fn (Node $node): bool => $node instanceof Folder && $node->isReadable() && !str_starts_with($node->getName(), '.'),
		));
		return ['role' => $role, 'mediaCount' => $mediaCount, 'folderCount' => $folderCount, 'covers' => $covers];
	}

	/** @return array{count: int, covers: list<array{id: int, name: string, mimeType: string, etag: string}>} */
	private function unindexedAlbumMedia(Folder $folder, int $remaining): array {
		$count = 0;
		$covers = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($count >= $remaining) break;
			if (!$node->isReadable() || str_starts_with($node->getName(), '.')) continue;
			if ($node instanceof File && $this->mediaTypes->supports($node)) {
				$count++;
				if (count($covers) < 3) $covers[] = [
					'id' => $node->getId(), 'name' => $node->getName(),
					'mimeType' => $node->getMimeType(), 'etag' => $node->getEtag(),
				];
			} elseif ($node instanceof Folder) {
				$nested = $this->unindexedAlbumMedia($node, $remaining - $count);
				$count += $nested['count'];
				foreach ($nested['covers'] as $cover) {
					if (count($covers) >= 3) break;
					$covers[] = $cover;
				}
			}
		}
		return ['count' => $count, 'covers' => $covers];
	}

	private function folderAt(Folder $root, string $path): Folder {
		if ($path === '') {
			return $root;
		}
		if (in_array('..', explode('/', $path), true)) {
			throw new \OCP\Files\NotFoundException('Invalid gallery path');
		}
		$node = $root->get($path);
		if (!$node instanceof Folder || !$node->isReadable() || !$root->isSubNode($node)) {
			throw new \OCP\Files\NotFoundException('Gallery folder not found');
		}
		return $node;
	}
	private static function group(Node $node): string {
		if ($node instanceof Folder) return 'folder';
		return str_starts_with($node->getMimeType(), 'video/') ? 'video' : 'image';
	}

}
