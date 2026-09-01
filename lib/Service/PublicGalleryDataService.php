<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

final class PublicGalleryDataService {
	public function __construct(
		private CollectionService $collections,
		private MediaMetadataService $metadata,
		private CapabilityPolicyService $capabilities,
		private MediaIndexService $mediaIndex,
		private PolicyService $policies,
		private PublicLinkScopeService $linkScopes,
		private PublicMediaResolver $publicMedia,
		private MediaTypePolicy $mediaTypes,
		private VideoTranscodeService $videoTranscodes,
	) {
	}

	/** @return array<string, mixed> */
	public function page(PublicShareContext $context, PublicGalleryQuery $query = new PublicGalleryQuery()): array {
		$gallery = $context->gallery;
		$root = $context->root;
		$link = $context->link;
		$limit = $query->limit;
		$offset = $query->offset;
		$path = $query->path;
		$search = $query->search;
		$sortBy = $query->sortBy;
		$sortDirection = $query->sortDirection;
		$groupBy = $query->groupBy;
		$cursor = $query->cursor;
		$pageNumber = $query->page;
		$focusId = $query->focusId;
		$limit = max(1, min(200, $limit));
		$offset = $pageNumber === null ? max(0, $offset) : (max(1, $pageNumber) - 1) * $limit;
		$path = trim($path, '/');
		$search = mb_substr(trim($search), 0, 120);
		$settings = $context->settings->withPublicPolicy($context->policy);
		$sortBy = $sortBy !== '' ? $sortBy : $settings->navigation->sortBy;
		$sortDirection = $sortDirection !== '' ? $sortDirection : $settings->navigation->sortDirection;
		$groupBy = $groupBy !== '' ? $groupBy : $settings->navigation->groupBy;
		if (!in_array($sortBy, ['name', 'modified', 'size'], true)
			|| !in_array($sortDirection, ['asc', 'desc'], true)
			|| !in_array($groupBy, ['none', 'type', 'folder'], true)) {
			throw new \InvalidArgumentException('Invalid gallery arrangement');
		}
		if ($gallery->getSourceType() === 'collection') {
			if ($path !== '') {
				throw new \OCP\Files\NotFoundException('Collections do not contain folders');
			}
			$nodes = array_values(array_filter(
				$this->collections->availableItems($gallery),
				static fn (array $item): bool => $search === '' || mb_stripos((string)$item['name'], $search) !== false,
			));
			$nodes = array_map(function (array $item) use ($gallery, $settings): array {
				try {
					$file = $this->collections->resolveMedia($gallery, (int)$item['id']);
					$item['metadata'] = $this->metadata->publicSummary($file, $settings->metadata->publicFields);
					$item = [...$item, ...$this->publicGeometry($file)];
				} catch (\Throwable) {
					$item['metadata'] = ['state' => 'unavailable'];
				}
				return $item;
			}, $nodes);
			$focusIndex = $focusId === null ? null : $this->arrayItemPosition($nodes, $focusId);
			if ($focusId !== null && $focusIndex === null) throw new \OCP\Files\NotFoundException('Gallery media not found');
			$offset = $this->pageOffset(count($nodes), $limit, $offset, $focusIndex);
			return $this->response(
				$context,
				array_slice($nodes, $offset, $limit),
				count($nodes),
				$limit,
				$offset,
				'',
				$search,
				'collection',
				'asc',
				'none',
				null,
				null,
				[],
				['indexed' => count($nodes), 'limit' => count($nodes), 'limitReached' => false, 'complete' => true],
				['startPath' => '', 'viewMode' => 'collection', 'groupDepth' => 1],
				$focusIndex,
			);
		}
		if (!$settings->navigation->folders && $path !== '') {
			throw new \OCP\Files\NotFoundException('Folder navigation is disabled');
		}
		$startPath = $this->linkScopes->normalize($link->getStartPath());
		$scopedRoot = $root;
		$recursive = $link->getViewMode() === 'recursive';
		$groupDepth = max(1, min(8, $link->getGroupDepth() ?: $settings->navigation->groupDepth));
		if ($recursive) {
			$relativePath = $this->linkScopes->normalize($path);
			$indexPath = $this->linkScopes->indexPath($link, $relativePath);
			$minOwnerRating = $link->getMinOwnerRating();
			$focusIndex = null;
			if ($focusId !== null) {
				$this->publicMedia->resolve($context, $focusId);
				$focusIndex = $this->mediaIndex->positionOf($gallery, $focusId, $indexPath, $search, $sortBy, $sortDirection, $minOwnerRating);
				if ($focusIndex === null) throw new \OCP\Files\NotFoundException('Gallery media not found');
				$offset = $this->pageOffset(PHP_INT_MAX, $limit, $offset, $focusIndex);
			}
			$page = $this->mediaIndex->page($gallery, $limit, $cursor, $indexPath, $search, $sortBy, $sortDirection, $minOwnerRating, $offset);
			$items = [];
			foreach ($page['items'] as $item) {
				try {
					$file = $this->publicMedia->resolve($context, (int)$item['id']);
					$item['folder'] = false;
					$item['group'] = $this->indexedGroup((string)$item['relativePath'], (string)$item['mimeType'], $indexPath, $groupBy, $groupDepth);
					$item['metadata'] = $this->metadata->publicSummary($file, $settings->metadata->publicFields);
					$item = [...$item, ...$this->publicGeometry($file)];
					$items[] = $item;
				} catch (\Throwable) {
					// Stale index entries never become public through a missing node.
				}
			}
			$summary = $this->mediaIndex->summary($gallery, $indexPath, $search, $groupBy, $groupDepth, $minOwnerRating);
			$page['total'] = array_sum($summary['groups']);
			return $this->response(
				$context,
				$items,
				$page['total'],
				$limit,
				$offset,
				$relativePath,
				$search,
				$sortBy,
				$sortDirection,
				$groupBy,
				$page['nextCursor'],
				$page['previousCursor'],
				$summary['groups'],
				array_diff_key($summary, ['groups' => true]),
				['startPath' => $startPath, 'viewMode' => 'recursive', 'groupDepth' => $groupDepth],
				$focusIndex,
			);
		}
		$currentFolder = $this->folderAt($scopedRoot, $path);
		$nodes = array_values(array_filter(
			$currentFolder->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->mediaTypes->supports($node))),
		));
		$nodes = array_values(array_filter(
			$nodes,
				static fn (Node $node): bool => ($settings->navigation->folders || !($node instanceof Folder))
				&& ($search === '' || mb_stripos($node->getName(), $search) !== false),
		));
		usort($nodes, static function (Node $left, Node $right) use ($sortBy, $sortDirection, $groupBy): int {
			if ($groupBy === 'type') {
				$group = self::group($left) <=> self::group($right);
				if ($group !== 0) return $group;
			}
			if ($sortBy !== 'name') {
				$folderOrder = ($left instanceof Folder ? 0 : 1) <=> ($right instanceof Folder ? 0 : 1);
				if ($folderOrder !== 0) return $folderOrder;
			}
			$result = match ($sortBy) {
				'modified' => $left->getMTime() <=> $right->getMTime(),
				'size' => $left->getSize() <=> $right->getSize(),
				default => strnatcasecmp($left->getName(), $right->getName()),
			};
			if ($result === 0) $result = strnatcasecmp($left->getName(), $right->getName());
			return $sortDirection === 'desc' ? -$result : $result;
		});
		$focusIndex = $focusId === null ? null : $this->nodePosition($nodes, $focusId);
		if ($focusId !== null && $focusIndex === null) throw new \OCP\Files\NotFoundException('Gallery media not found');
		$offset = $this->pageOffset(count($nodes), $limit, $offset, $focusIndex);

		$items = array_map(function (Node $node) use ($settings): array {
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
			return $node instanceof File ? [...$item, ...$this->publicGeometry($node)] : $item;
		}, array_slice($nodes, $offset, $limit));

		$groups = [];
		foreach ($nodes as $node) {
			$key = $groupBy === 'none' ? 'all' : self::group($node);
			$groups[$key] = ($groups[$key] ?? 0) + 1;
		}
		return $this->response(
			$context,
			$items,
			count($nodes),
			$limit,
			$offset,
			$path,
			$search,
			$sortBy,
			$sortDirection,
			$groupBy,
			null,
			null,
			$groups,
			['indexed' => count($nodes), 'limit' => $this->policies->get('maxIndexedMedia'), 'limitReached' => false, 'complete' => true],
			['startPath' => $startPath, 'viewMode' => 'folder', 'groupDepth' => $groupDepth],
			$focusIndex,
		);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param array<string, int> $groups
	 * @param array<string, mixed> $indexState
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function response(
		PublicShareContext $context,
		array $items,
		int $total,
		int $limit,
		int $offset,
		string $path,
		string $search,
		string $sortBy,
		string $sortDirection,
		string $groupBy,
		?string $nextCursor = null,
		?string $previousCursor = null,
		array $groups = [],
		array $indexState = [],
		array $scope = [],
		?int $focusIndex = null,
	): array {
		$gallery = $context->gallery;
		$settings = $context->settings->withPublicPolicy($context->policy);
		$effective = $this->capabilities->effective($settings);
		$serialized = $settings->jsonSerialize();
		foreach ($serialized['presentation']['story']['sections'] ?? [] as &$section) {
			$section['mediaIds'] = array_values(array_filter(
				$section['mediaIds'] ?? [],
				fn (mixed $fileId): bool => is_int($fileId) && $this->publicMedia->allows($context, $fileId),
			));
		}
		unset($section);
		$storyItems = $this->storyItems($context, $serialized, $items);
		if (!$effective['downloads']['allowed']) {
			$serialized['delivery']['downloadScope'] = 'none';
		}
		if (!$effective['guestUploads']['allowed']) {
			$serialized['delivery']['guestUploads'] = false;
		}
		foreach (['likes', 'colors', 'comments', 'annotations', 'selections'] as $feature) {
			if (!$effective[$feature]['allowed']) $serialized['review'][$feature] = false;
		}
		$serialized['review']['ratings'] = $serialized['review']['ratings'] && $this->capabilities->feature('guestRatings');
		$serialized['review']['pick'] = $serialized['review']['pick'] && $this->capabilities->feature('guestRatings');
		if ($context->link->getPublicLocale() !== null) $serialized['publicLocale'] = $context->link->getPublicLocale();
		$this->addPlayback($context, $items);
		$this->addPlayback($context, $storyItems);
		return [
			'gallery' => [
				'id' => $gallery->getId(),
				'title' => $gallery->getTitle(),
				'settings' => $serialized,
				'effectiveCapabilities' => $effective,
			],
			'items' => $items,
			's' => $storyItems,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'page' => intdiv($offset, $limit) + 1,
			'pageSize' => $limit,
			'pageCount' => max(1, (int)ceil($total / $limit)),
			'focusIndex' => $focusIndex,
			'previousCursor' => $previousCursor,
			'nextCursor' => $nextCursor,
			'path' => $path,
			'groups' => $groups,
			'indexState' => $indexState,
			'scope' => $scope,
			'view' => compact('search', 'sortBy', 'sortDirection', 'groupBy'),
		];
	}

	/** @param array<string, mixed> $settings
	 * @param list<array<string, mixed>> $pageItems
	 * @return list<array<string, mixed>>
	 */
	private function storyItems(PublicShareContext $context, array $settings, array $pageItems): array {
		$pageIds = array_fill_keys(array_map(static fn (array $item): int => (int)$item['id'], $pageItems), true);
		$storyIds = array_values(array_unique(array_merge(...array_map(
			static fn (array $section): array => $section['mediaIds'] ?? [],
			$settings['presentation']['story']['sections'] ?? [],
		))));
		$items = [];
		foreach ($storyIds as $fileId) {
			if (isset($pageIds[$fileId])) continue;
			try {
				$file = $this->publicMedia->resolve($context, $fileId);
				$items[] = [
					'id' => $fileId, 'name' => $file->getName(), 'mimeType' => $file->getMimeType(),
					'size' => (int)$file->getSize(), 'modifiedAt' => $file->getMTime(), 'etag' => $file->getEtag(), 'folder' => false,
					'metadata' => $this->metadata->publicSummary($file, $settings['metadata']['publicFields']),
					...$this->publicGeometry($file),
				];
			} catch (\Throwable) {
				// Removed, unreadable or out-of-scope story media stays private.
			}
		}
		return $items;
	}

	/** @param list<array<string, mixed>> $items */
	private function addPlayback(PublicShareContext $context, array &$items): void {
		foreach ($items as &$item) {
			if (!str_starts_with((string)($item['mimeType'] ?? ''), 'video/')) continue;
			try {
				$item['playback'] = $this->videoTranscodes->request(
					$context->gallery->getOwnerUid(),
					$this->publicMedia->resolve($context, (int)$item['id']),
				);
			} catch (\Throwable) {
				$item['playback'] = ['state' => 'unavailable', 'playable' => false];
			}
		}
		unset($item);
	}

	/** @param list<array<string, mixed>> $items */
	private function arrayItemPosition(array $items, int $fileId): ?int {
		foreach ($items as $index => $item) if ((int)($item['id'] ?? 0) === $fileId) return $index;
		return null;
	}

	/** @param list<Node> $nodes */
	private function nodePosition(array $nodes, int $fileId): ?int {
		foreach ($nodes as $index => $node) if ($node->getId() === $fileId) return $index;
		return null;
	}

	private function pageOffset(int $total, int $limit, int $requestedOffset, ?int $focusIndex): int {
		if ($focusIndex !== null) return intdiv($focusIndex, $limit) * $limit;
		if ($total === PHP_INT_MAX) return max(0, $requestedOffset);
		$lastOffset = max(0, (intdiv(max(0, $total - 1), $limit)) * $limit);
		return min(max(0, $requestedOffset), $lastOffset);
	}

	private static function group(Node $node): string {
		if ($node instanceof Folder) return 'folder';
		return str_starts_with($node->getMimeType(), 'video/') ? 'video' : 'image';
	}

	/** @return array{width?: int, height?: int} */
	private function publicGeometry(File $file): array {
		$summary = $this->metadata->summary($file);
		$width = (int)($summary['width'] ?? 0);
		$height = (int)($summary['height'] ?? 0);
		return $width > 0 && $height > 0 ? compact('width', 'height') : [];
	}

	private function folderAt(Folder $root, string $path): Folder {
		if ($path === '') {
			return $root;
		}
		if (in_array('..', explode('/', $path), true)) {
			throw new \OCP\Files\NotFoundException('Invalid gallery path');
		}
		$node = $root->get($path);
		if (!$node instanceof Folder || !$root->isSubNode($node)) {
			throw new \OCP\Files\NotFoundException('Gallery folder not found');
		}
		return $node;
	}

	private function indexedGroup(string $relativePath, string $mimeType, string $pathPrefix, string $groupBy, int $groupDepth): string {
		if ($groupBy === 'none') return 'all';
		if ($groupBy === 'type') return str_starts_with($mimeType, 'video/') ? 'video' : 'image';
		$relative = $pathPrefix === '' ? $relativePath : substr($relativePath, strlen($pathPrefix) + 1);
		$parts = explode('/', $relative);
		array_pop($parts);
		return $parts === [] ? 'root' : implode('/', array_slice($parts, 0, $groupDepth));
	}

}
