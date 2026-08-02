<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\MediaIndex;
use OCA\ProofingGallery\Db\MediaIndexMapper;
use OCA\ProofingGallery\Dto\MediaIndexQuery;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Lock\ILockingProvider;

final class MediaIndexService {
	use TTransactional;

	public function __construct(
		private MediaIndexMapper $index,
		private FolderService $folders,
		private PolicyService $policies,
		private ITimeFactory $clock,
		private MediaCursorCodec $cursors,
		private MediaTypePolicy $mediaTypes,
		private IDBConnection $db,
		private ILockingProvider $locks,
	) {
	}

	/** @return array{indexed: int, removed: int, truncated: bool, generation: string} */
	public function rebuild(Gallery $gallery): array {
		if ($gallery->getSourceType() !== 'folder') throw new \InvalidArgumentException('Only folder galleries can be indexed');
		$lockPath = 'proofing-gallery/media-index/' . $gallery->getId();
		$this->locks->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, 'Proofing Gallery media index');
		try {
			return $this->rebuildLocked($gallery);
		} finally {
			$this->locks->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array{indexed: int, removed: int, truncated: bool, generation: string} */
	private function rebuildLocked(Gallery $gallery): array {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$rootStorage = $root->getStorage()->getId();
		$generation = bin2hex(random_bytes(16));
		$now = $this->clock->getTime();
		$limit = $this->policies->get('maxIndexedMedia');
		$truncated = false;
		/** @var list<array{fileId: int, parentId: int, relativePath: string, depth: int, file: array{name: string, mimeType: string, size: int, mtime: int, etag: string}}> $discovered */
		$discovered = [];
		/** @var list<array{0: Folder, 1: string, 2: int}> $pending */
		$pending = [[$root, '', 0]];

		while ($pending !== [] && !$truncated) {
			[$folder, $folderPath, $depth] = array_pop($pending);
			$nodes = array_values(array_filter(
				$folder->getDirectoryListing(),
				static fn (Node $node): bool => !str_starts_with($node->getName(), '.'),
			));
			usort($nodes, static fn (Node $left, Node $right): int => strnatcasecmp($right->getName(), $left->getName()));
			foreach ($nodes as $node) {
				if (!$node->isReadable() || $node->getStorage()->getId() !== $rootStorage) continue;
				$relativePath = ltrim($folderPath . '/' . $node->getName(), '/');
				if ($node instanceof Folder) {
					$pending[] = [$node, $relativePath, $depth + 1];
					continue;
				}
				if (!$node instanceof File || !$this->mediaTypes->supports($node->getMimeType())) continue;
				if (count($discovered) >= $limit) {
					$truncated = true;
					break;
				}
				$discovered[] = [
					'fileId' => (int)$node->getId(),
					'parentId' => (int)$folder->getId(),
					'relativePath' => $relativePath,
					'depth' => $depth,
					'file' => [
						'name' => $node->getName(),
						'mimeType' => $node->getMimeType(),
						'size' => (int)$node->getSize(),
						'mtime' => $node->getMTime(),
						'etag' => $node->getEtag(),
					],
				];
			}
		}

		$removed = $this->atomic(function () use ($gallery, $discovered, $generation, $now): int {
			foreach ($discovered as $entry) {
				$this->index->upsert($gallery->getId(), $entry['fileId'], $entry['parentId'], $entry['relativePath'], $entry['depth'], $generation, $now, $entry['file']);
			}
			return $this->index->deleteOtherGenerations($gallery->getId(), $generation);
		}, $this->db);

		return ['indexed' => count($discovered), 'removed' => $removed, 'truncated' => $truncated, 'generation' => $generation];
	}

	/** @return array{items: list<array<string, mixed>>, nextCursor: ?string, total: int} */
	public function page(
		Gallery $gallery,
		int $limit = 60,
		?string $cursor = null,
		string $pathPrefix = '',
		string $search = '',
		string $sortBy = 'name',
		string $sortDirection = 'asc',
		int $minOwnerRating = 0,
	): array {
		$query = MediaIndexQuery::fromInput($gallery->getId(), $gallery->getOwnerUid(), $limit, $pathPrefix, $search, $sortBy, $sortDirection, $minOwnerRating);
		$pageQuery = $query->withLimit($query->limit + 1);
		[$afterValue, $afterFileId] = $this->cursors->decode($cursor, $query);
		$entries = $this->index->page($pageQuery, $afterValue, $afterFileId);
		$hasMore = count($entries) > $query->limit;
		if ($hasMore) array_pop($entries);

		$items = [];
		foreach ($entries as $entry) {
			try {
				$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $entry->getFileId());
				if (!hash_equals($entry->getEtag(), $file->getEtag())) continue;
				$items[] = $entry->jsonSerialize();
			} catch (\Throwable) {
				// A stale cache row is skipped; the next reconciliation removes it.
			}
		}
		$last = $hasMore && $entries !== [] ? $entries[array_key_last($entries)] : null;
		return [
			'items' => $items,
			'nextCursor' => $last === null ? null : $this->cursors->encode($last, $query),
			'total' => $this->index->countFiltered($query),
		];
	}

	/** @return array{groups: array<string, int>, indexed: int, limit: int, limitReached: bool, complete: bool, state: string, lastIndexedAt: ?int} */
	public function summary(Gallery $gallery, string $pathPrefix, string $search, string $groupBy, int $groupDepth, int $minOwnerRating = 0): array {
		$query = MediaIndexQuery::fromInput($gallery->getId(), $gallery->getOwnerUid(), 1, $pathPrefix, $search, 'name', 'asc', $minOwnerRating);
		$pathPrefix = $query->pathPrefix;
		$groups = [];
		$rows = $this->index->groupingRows($query);
		foreach ($rows as $row) {
			$key = match ($groupBy) {
				'type' => str_starts_with($row['mime_type'], 'video/') ? 'video' : 'image',
				'folder' => $this->folderGroup($row['relative_path'], $pathPrefix, $groupDepth),
				default => 'all',
			};
			$groups[$key] = ($groups[$key] ?? 0) + 1;
		}
		ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
		$indexed = $this->index->countGallery($gallery->getId());
		$limit = $this->policies->get('maxIndexedMedia');
		$lastIndexedAt = $this->index->lastSeenAt($gallery->getId());
		$state = $indexed === 0 ? 'unindexed' : ($indexed >= $limit ? 'limit_reached' : 'ready');
		return [
			'groups' => $groups,
			'indexed' => $indexed,
			'limit' => $limit,
			'limitReached' => $indexed >= $limit,
			'complete' => $state === 'ready',
			'state' => $state,
			'lastIndexedAt' => $lastIndexedAt,
		];
	}

	private function folderGroup(string $relativePath, string $pathPrefix, int $groupDepth): string {
		$relative = $pathPrefix === '' ? $relativePath : substr($relativePath, strlen($pathPrefix) + 1);
		$parts = explode('/', $relative);
		array_pop($parts);
		if ($parts === []) return 'root';
		return implode('/', array_slice($parts, 0, max(1, $groupDepth)));
	}

}
