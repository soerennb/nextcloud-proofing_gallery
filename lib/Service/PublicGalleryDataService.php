<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Dto\GallerySettings;
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
		private PublicLinkPolicyService $linkPolicies,
	) {
	}

	/** @return array<string, mixed> */
	public function page(
		Gallery $gallery,
		Folder $root,
		int $limit = 60,
		int $offset = 0,
		string $path = '',
		string $search = '',
		string $sortBy = '',
		string $sortDirection = '',
		string $groupBy = '',
		?string $cursor = null,
		?PublicLink $link = null,
		bool $nativeRootIsScope = false,
	): array {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$path = trim($path, '/');
		$search = mb_substr(trim($search), 0, 120);
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$sortBy = $sortBy !== '' ? $sortBy : $settings->navigation['sortBy'];
		$sortDirection = $sortDirection !== '' ? $sortDirection : $settings->navigation['sortDirection'];
		$groupBy = $groupBy !== '' ? $groupBy : $settings->navigation['groupBy'];
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
					$item['metadata'] = $this->metadata->publicSummary(
						$this->collections->resolveMedia($gallery, (int)$item['id']),
						$settings->metadata['publicFields'],
					);
				} catch (\Throwable) {
					$item['metadata'] = ['state' => 'unavailable'];
				}
				return $item;
			}, $nodes);
			return $this->response(
				$gallery,
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
				[],
				['indexed' => count($nodes), 'limit' => count($nodes), 'limitReached' => false, 'complete' => true],
				['startPath' => '', 'viewMode' => 'collection', 'groupDepth' => 1],
				$link,
			);
		}
		if (!$settings->navigation['folders'] && $path !== '') {
			throw new \OCP\Files\NotFoundException('Folder navigation is disabled');
		}
		$startPath = $link === null ? '' : $this->linkScopes->normalize($link->getStartPath());
		$scopedRoot = $nativeRootIsScope ? $root : $this->folderAt($root, $startPath);
		$recursive = $link?->getViewMode() === 'recursive';
		$groupDepth = max(1, min(8, $link?->getGroupDepth() ?: $settings->navigation['groupDepth']));
		if ($recursive) {
			$relativePath = $this->linkScopes->normalize($path);
			$indexPath = $link === null ? $relativePath : $this->linkScopes->indexPath($link, $relativePath);
			$minOwnerRating = $link?->getMinOwnerRating() ?? 0;
			$page = $this->mediaIndex->page($gallery, $limit, $cursor, $indexPath, $search, $sortBy, $sortDirection, $minOwnerRating);
			$items = [];
			foreach ($page['items'] as $item) {
				try {
					$file = $this->fileById($root, (int)$item['id']);
					$item['folder'] = false;
					$item['group'] = $this->indexedGroup((string)$item['relativePath'], (string)$item['mimeType'], $indexPath, $groupBy, $groupDepth);
					$item['metadata'] = $this->metadata->publicSummary($file, $settings->metadata['publicFields']);
					$items[] = $item;
				} catch (\Throwable) {
					// Stale index entries never become public through a missing node.
				}
			}
			$summary = $this->mediaIndex->summary($gallery, $indexPath, $search, $groupBy, $groupDepth, $minOwnerRating);
			$page['total'] = array_sum($summary['groups']);
			return $this->response(
				$gallery,
				$items,
				$page['total'],
				$limit,
				0,
				$relativePath,
				$search,
				$sortBy,
				$sortDirection,
				$groupBy,
				$page['nextCursor'],
				$summary['groups'],
				array_diff_key($summary, ['groups' => true]),
				['startPath' => $startPath, 'viewMode' => 'recursive', 'groupDepth' => $groupDepth],
				$link,
			);
		}
		$currentFolder = $this->folderAt($scopedRoot, $path);
		$nodes = array_values(array_filter(
			$currentFolder->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node))),
		));
		$nodes = array_values(array_filter(
			$nodes,
			static fn (Node $node): bool => ($settings->navigation['folders'] || !($node instanceof Folder))
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

		$items = array_map(fn (Node $node): array => [
			'id' => $node->getId(),
			'name' => $node->getName(),
			'mimeType' => $node->getMimeType(),
			'size' => (int)$node->getSize(),
			'modifiedAt' => $node->getMTime(),
			'etag' => $node->getEtag(),
			'folder' => $node instanceof Folder,
			'group' => self::group($node),
			'metadata' => $node instanceof File
				? $this->metadata->publicSummary($node, $settings->metadata['publicFields'])
				: ['state' => 'unavailable'],
		], array_slice($nodes, $offset, $limit));

		$groups = [];
		foreach ($nodes as $node) {
			$key = $groupBy === 'none' ? 'all' : self::group($node);
			$groups[$key] = ($groups[$key] ?? 0) + 1;
		}
		return $this->response(
			$gallery,
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
			$groups,
			['indexed' => count($nodes), 'limit' => $this->policies->get('maxIndexedMedia'), 'limitReached' => false, 'complete' => true],
			['startPath' => $startPath, 'viewMode' => 'folder', 'groupDepth' => $groupDepth],
			$link,
		);
	}

	/** @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private function response(
		Gallery $gallery,
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
		array $groups = [],
		array $indexState = [],
		array $scope = [],
		?PublicLink $link = null,
	): array {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$effective = $this->capabilities->effective($settings);
		$serialized = $settings->jsonSerialize();
		if (!$effective['downloads']['allowed']) {
			$serialized['delivery']['downloadScope'] = 'none';
			$serialized['allowDownloads'] = false;
		}
		if (!$effective['guestUploads']['allowed']) {
			$serialized['delivery']['guestUploads'] = false;
			$serialized['allowGuestUploads'] = false;
		}
		foreach (['likes', 'colors', 'comments', 'annotations', 'selections'] as $feature) {
			if (!$effective[$feature]['allowed']) $serialized['review'][$feature] = false;
		}
		if ($link !== null) {
			$policy = $this->linkPolicies->validate(json_decode($link->getPolicy(), true, flags: JSON_THROW_ON_ERROR));
			foreach (['likes', 'colors', 'comments', 'annotations', 'selections'] as $feature) {
				$serialized['review'][$feature] = $serialized['review'][$feature] && $policy[$feature];
			}
			$serialized['review']['ratings'] = $serialized['review']['ratings'] && $policy['ratings'] && $this->capabilities->feature('guestRatings');
			$serialized['review']['pick'] = $serialized['review']['pick'] && $policy['pick'] && $this->capabilities->feature('guestRatings');
			$serialized['delivery']['guestUploads'] = $serialized['delivery']['guestUploads'] && $policy['upload'];
			$serialized['allowGuestUploads'] = $serialized['delivery']['guestUploads'];
			$allowedDownloads = ['none' => [], 'individual' => ['individual'], 'selection' => ['selection'], 'all' => ['individual', 'selection']];
			$intersection = array_values(array_intersect($allowedDownloads[$serialized['delivery']['downloadScope']], $allowedDownloads[$policy['downloadScope']]));
			$serialized['delivery']['downloadScope'] = match ($intersection) {
				['individual'] => 'individual',
				['selection'] => 'selection',
				['individual', 'selection'] => 'all',
				default => 'none',
			};
			$serialized['allowDownloads'] = $serialized['delivery']['downloadScope'] !== 'none';
			if (!$policy['metadata']) $serialized['metadata']['publicFields'] = [];
			if ($link->getPublicLocale() !== null) $serialized['publicLocale'] = $link->getPublicLocale();
			if (!$policy['likes'] && !$policy['colors'] && !$policy['comments'] && !$policy['annotations'] && !$policy['selections'] && !$policy['ratings'] && !$policy['pick']) {
				$serialized['mode'] = 'presentation';
			}
		}
		return [
			'gallery' => [
				'id' => $gallery->getId(),
				'title' => $gallery->getTitle(),
				'settings' => $serialized,
				'effectiveCapabilities' => $effective,
			],
			'items' => $items,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'nextCursor' => $nextCursor,
			'path' => $path,
			'groups' => $groups,
			'indexState' => $indexState,
			'scope' => $scope,
			'view' => compact('search', 'sortBy', 'sortDirection', 'groupBy'),
		];
	}

	private static function group(Node $node): string {
		if ($node instanceof Folder) return 'folder';
		return str_starts_with($node->getMimeType(), 'video/') ? 'video' : 'image';
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

	private function fileById(Folder $root, int $fileId): File {
		foreach ($root->getById($fileId) as $node) {
			if ($node instanceof File && $root->isSubNode($node) && $this->isSupported($node)) return $node;
		}
		throw new \OCP\Files\NotFoundException('Media file not found');
	}

	private function indexedGroup(string $relativePath, string $mimeType, string $pathPrefix, string $groupBy, int $groupDepth): string {
		if ($groupBy === 'none') return 'all';
		if ($groupBy === 'type') return str_starts_with($mimeType, 'video/') ? 'video' : 'image';
		$relative = $pathPrefix === '' ? $relativePath : substr($relativePath, strlen($pathPrefix) + 1);
		$parts = explode('/', $relative);
		array_pop($parts);
		return $parts === [] ? 'root' : implode('/', array_slice($parts, 0, $groupDepth));
	}

	private function isSupported(File $file): bool {
		return str_starts_with($file->getMimeType(), 'image/')
			|| in_array($file->getMimeType(), ['video/mp4', 'video/webm'], true);
	}
}
