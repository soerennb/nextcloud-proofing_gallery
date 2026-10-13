<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
use OCA\ProofingGallery\Dto\PublicGalleryPage;
use OCA\ProofingGallery\Domain\MediaSort;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

final class FolderPublicPageReader {
	public function __construct(private MediaIndexService $mediaIndex, private MediaMetadataService $metadata, private MediaTypePolicy $mediaTypes, private PolicyService $policies) {}

	public function read(PublicShareContext $context, PublicGalleryQuery $query): PublicGalleryPage {
		$gallery = $context->gallery; $settings = $context->settings;
		$limit = $query->limit; $offset = $query->offset; $path = $query->path; $search = $query->search;
		$sortBy = $query->sortBy; $sortDirection = $query->sortDirection; $groupBy = $query->groupBy;
		$focusId = $query->focusId;
		$link = $context->link; $cursor = $query->cursor; $multiRoot = $context->scope->multiRoot;
		$startPath = $context->scope->startPath; $scopedRoot = $context->root;
		$groupDepth = max(1, min(8, $link->getGroupDepth() ?: $settings->navigation->groupDepth));

		$eventAlbums = $gallery->getDeliveryMode() === 'event' && $path === '' && $multiRoot;
		$rootDetails = $gallery->getDeliveryMode() === 'event' && $multiRoot ? $context->scope->details : [];
		$eventRoots = [];
		if ($eventAlbums) {
			$nodes = [];
			foreach ($rootDetails as $detail) {
				try { $node = $scopedRoot->get($detail['path']); } catch (\Throwable) { continue; }
				if (!$node instanceof Folder || !$node->isReadable() || !$scopedRoot->isSubNode($node)) continue;
				$nodes[] = $node;
				$eventRoots[$node->getId()] = $detail;
			}
		} else {
			$currentFolder = $this->folderAt($scopedRoot, $path);
			$nodes = array_values(array_filter(
				$currentFolder->getDirectoryListing(),
				fn (Node $node): bool => $node->isReadable() && !str_starts_with($node->getName(), '.')
					&& ($node instanceof Folder || ($node instanceof File && $this->mediaTypes->supports($node)))
					&& (!$multiRoot || $context->scope->visiblePath(ltrim($path . '/' . $node->getName(), '/'))),
			));
		}
		$nodes = array_values(array_filter(
			$nodes,
			static fn (Node $node): bool => ($multiRoot || $settings->navigation->folders || !($node instanceof Folder))
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
			if ($sortBy !== 'name') {
				$folderOrder = ($left instanceof Folder ? 0 : 1) <=> ($right instanceof Folder ? 0 : 1);
				if ($folderOrder !== 0) return $folderOrder;
			}
			$value = static fn (Node $node): array => [
				'id' => $node->getId(), 'name' => $node->getName(), 'size' => $node->getSize(), 'modifiedAt' => $node->getMTime(),
				'capturedAt' => $captureById[$node->getId()] ?? null,
			];
			return MediaSort::compare($value($left), $value($right), $sortBy, $sortDirection);
		});
		$focusIndex = $focusId === null ? null : \OCA\ProofingGallery\Domain\PublicPagePagination::nodePosition($nodes, $focusId);
		if ($focusId !== null && $focusIndex === null) throw new \OCP\Files\NotFoundException('Gallery media not found');
		$offset = \OCA\ProofingGallery\Domain\PublicPagePagination::offset(count($nodes), $limit, $offset, $focusIndex);

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
		}, array_slice($nodes, $offset, $limit));

		$groups = [];
		foreach ($nodes as $node) {
			$key = $groupBy === 'none' ? 'all' : self::group($node);
			$groups[$key] = ($groups[$key] ?? 0) + 1;
		}
		return new PublicGalleryPage($items, count($nodes), $query, $offset,
			nextCursor: null, previousCursor: null, groups: $groups,
			indexState: ['indexed' => count($nodes), 'limit' => $this->policies->get('maxIndexedMedia'), 'limitReached' => false, 'complete' => true], scope: ['startPath' => $startPath, 'allowedRoots' => $context->scope->roots, 'roots' => $rootDetails, 'viewMode' => 'folder', 'groupDepth' => $groupDepth], focusIndex: $focusIndex,
		);
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
