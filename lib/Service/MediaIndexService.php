<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\MediaIndex;
use OCA\ProofingGallery\Db\MediaIndexMapper;
use OCA\ProofingGallery\Db\MediaIndexScanRepository;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Dto\MediaIndexQuery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Lock\ILockingProvider;

final class MediaIndexService {
	private const SCAN_BATCH_SIZE = 500;
	private const DIRECTORY_MIME = 'httpd/unix-directory';

	public function __construct(
		private MediaIndexMapper $index,
		private FolderService $folders,
		private PolicyService $policies,
		private ITimeFactory $clock,
		private MediaCursorCodec $cursors,
		private MediaTypePolicy $mediaTypes,
		private MediaIndexScanRepository $scans,
		private IJobList $jobs,
		private ILockingProvider $locks,
	) {
	}

	/** @return array{indexed: int, removed: int, truncated: bool, generation: string, complete: bool} */
	public function rebuild(Gallery $gallery): array {
		$result = $this->scanBatch($gallery, true);
		if (!$result['complete']) $this->queueContinuation($gallery);
		return $result;
	}

	/** @return array{indexed: int, removed: int, truncated: bool, generation: string, complete: bool} */
	public function continueRebuild(Gallery $gallery): array {
		$result = $this->scanBatch($gallery, false);
		if (!$result['complete']) $this->queueContinuation($gallery);
		return $result;
	}

	/** @return array{indexed: int, removed: int, truncated: bool, generation: string, complete: bool} */
	private function scanBatch(Gallery $gallery, bool $requestRebuild): array {
		if ($gallery->getSourceType() !== 'folder') throw new \InvalidArgumentException('Only folder galleries can be indexed');
		$lockPath = 'proofing-gallery/media-index/' . $gallery->getId();
		$this->locks->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE, 'Proofing Gallery media index');
		try {
			return $this->scanBatchLocked($gallery, $requestRebuild);
		} finally {
			$this->locks->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array{indexed: int, removed: int, truncated: bool, generation: string, complete: bool} */
	private function scanBatchLocked(Gallery $gallery, bool $requestRebuild): array {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$now = $this->clock->getTime();
		$scan = $this->scans->find((int)$gallery->getId());
		if ($scan === null || (string)$scan['status'] !== 'running') {
			$this->startScan($gallery, $root, $now);
			$scan = $this->scans->find((int)$gallery->getId());
		} elseif ($requestRebuild) {
			$this->scans->markDirty((int)$gallery->getId(), $now);
			$scan['dirty'] = true;
		}
		if ($scan === null) throw new \RuntimeException('Media scan could not be initialized');

		$generation = (string)$scan['generation'];
		$indexed = (int)$scan['indexed_count'];
		$limit = $this->policies->get('maxIndexedMedia');
		$truncated = (bool)$scan['truncated'];
		$remainingRows = self::SCAN_BATCH_SIZE;
		while ($remainingRows > 0 && !$truncated) {
			$folder = $this->scans->nextFolder((int)$gallery->getId(), $generation);
			if ($folder === null) break;
			$queryLimit = $remainingRows;
			$rows = $this->scans->children(
				(int)$scan['root_storage_id'],
				(int)$folder['parent_file_id'],
				(int)$folder['after_file_id'],
				$queryLimit,
			);
			if ($rows === []) {
				$this->scans->completeFolder((int)$folder['id']);
				continue;
			}

			foreach ($rows as $row) {
				$remainingRows--;
				$this->scans->advanceFolder((int)$folder['id'], $row['file_id']);
				if (str_starts_with($row['name'], '.')) continue;
				$relativePath = ltrim((string)$folder['relative_path'] . '/' . $row['name'], '/');
				if ($row['mime_type'] === self::DIRECTORY_MIME) {
					$this->scans->enqueue((int)$gallery->getId(), $generation, $row['file_id'], $relativePath, (int)$folder['depth'] + 1);
					continue;
				}
				if (!$this->mediaTypes->supports($row['mime_type'])) continue;
				if ($indexed >= $limit) {
					$truncated = true;
					break;
				}
				$this->index->upsert((int)$gallery->getId(), $row['file_id'], $row['parent'], $relativePath, (int)$folder['depth'], $generation, $now, [
					'name' => $row['name'], 'mimeType' => $row['mime_type'], 'size' => $row['size'], 'mtime' => $row['mtime'], 'etag' => $row['etag'],
				]);
				$indexed++;
			}
			if (count($rows) < $queryLimit) $this->scans->completeFolder((int)$folder['id']);
		}
		$this->scans->progress((int)$gallery->getId(), $indexed, $truncated, $now);
		$complete = $truncated || $this->scans->nextFolder((int)$gallery->getId(), $generation) === null;
		if (!$complete) return ['indexed' => $indexed, 'removed' => 0, 'truncated' => false, 'generation' => $generation, 'complete' => false];

		$removed = $this->index->deleteOtherGenerations((int)$gallery->getId(), $generation);
		if ((bool)$scan['dirty']) {
			$this->startScan($gallery, $root, $now);
			$newScan = $this->scans->find((int)$gallery->getId());
			return ['indexed' => 0, 'removed' => $removed, 'truncated' => false, 'generation' => (string)$newScan['generation'], 'complete' => false];
		}
		$this->scans->deleteQueue((int)$gallery->getId());
		$this->scans->finish((int)$gallery->getId(), $truncated, $now);
		return ['indexed' => $indexed, 'removed' => $removed, 'truncated' => $truncated, 'generation' => $generation, 'complete' => true];
	}

	private function startScan(Gallery $gallery, \OCP\Files\Folder $root, int $now): void {
		$this->scans->start(
			(int)$gallery->getId(),
			bin2hex(random_bytes(16)),
			(int)$root->getStorage()->getCache()->getNumericStorageId(),
			(int)$root->getId(),
			$now,
		);
	}

	private function queueContinuation(Gallery $gallery): void {
		$this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => (int)$gallery->getId(), 'continuation' => true]);
	}

	/** @return array{items: list<array<string, mixed>>, previousCursor: ?string, nextCursor: ?string, total: int} */
	public function page(
		Gallery $gallery,
		int $limit = 60,
		?string $cursor = null,
		string $pathPrefix = '',
		string $search = '',
		string $sortBy = 'name',
		string $sortDirection = 'asc',
		int $minOwnerRating = 0,
		int $offset = 0,
	): array {
		$query = MediaIndexQuery::fromInput($gallery->getId(), $gallery->getOwnerUid(), $limit, $pathPrefix, $search, $sortBy, $sortDirection, $minOwnerRating);
		$pageQuery = $query->withLimit($query->limit + 1);
		[$afterValue, $afterFileId, $cursorDirection] = $this->cursors->decode($cursor, $query);
		$before = $cursorDirection === 'previous';
		$entries = $this->index->page($pageQuery, $afterValue, $afterFileId, $before, $cursor === null ? $offset : 0);
		$hasMore = count($entries) > $query->limit;
		if ($hasMore) {
			if ($before) array_shift($entries);
			else array_pop($entries);
		}

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
		$first = $entries === [] ? null : $entries[0];
		$last = $entries === [] ? null : $entries[array_key_last($entries)];
		return [
			'items' => $items,
			'previousCursor' => $first === null || (!$before && $cursor === null && $offset === 0) || ($before && !$hasMore)
				? null : $this->cursors->encode($first, $query, 'previous'),
			'nextCursor' => $last === null || (!$before && !$hasMore)
				? null : $this->cursors->encode($last, $query, 'next'),
			'total' => $this->index->countFiltered($query),
		];
	}

	/** @return ?list<array{file_id: int, relative_path: string, mime_type: string}> */
	public function downloadableRows(Gallery $gallery, string $pathPrefix, int $minOwnerRating): ?array {
		$summary = $this->summary($gallery, $pathPrefix, '', 'none', 1, $minOwnerRating);
		if (!$summary['complete']) return null;
		$query = MediaIndexQuery::fromInput(
			$gallery->getId(), $gallery->getOwnerUid(), 1, $pathPrefix, '', 'name', 'asc', $minOwnerRating,
		);
		$rows = [];
		$afterFileId = 0;
		do {
			$batch = $this->index->groupingRows($query, $afterFileId, 1000);
			foreach ($batch as $row) {
				$rows[] = $row;
				$afterFileId = $row['file_id'];
			}
		} while (count($batch) === 1000);
		return $rows;
	}

	public function positionOf(
		Gallery $gallery,
		int $fileId,
		string $pathPrefix = '',
		string $search = '',
		string $sortBy = 'name',
		string $sortDirection = 'asc',
		int $minOwnerRating = 0,
	): ?int {
		$query = MediaIndexQuery::fromInput($gallery->getId(), $gallery->getOwnerUid(), 1, $pathPrefix, $search, $sortBy, $sortDirection, $minOwnerRating);
		return $this->index->positionOf($query, $fileId);
	}

	/** @return array{groups: array<string, int>, indexed: int, limit: int, limitReached: bool, complete: bool, state: string, lastIndexedAt: ?int} */
	public function summary(Gallery $gallery, string $pathPrefix, string $search, string $groupBy, int $groupDepth, int $minOwnerRating = 0): array {
		$query = MediaIndexQuery::fromInput($gallery->getId(), $gallery->getOwnerUid(), 1, $pathPrefix, $search, 'name', 'asc', $minOwnerRating);
		$pathPrefix = $query->pathPrefix;
		$groups = [];
		if ($groupBy === 'type') {
			foreach ($this->index->mimeCounts($query) as $mimeType => $count) {
				$key = str_starts_with($mimeType, 'video/') ? 'video' : 'image';
				$groups[$key] = ($groups[$key] ?? 0) + $count;
			}
		} elseif ($groupBy === 'folder') {
			$afterFileId = 0;
			do {
				$rows = $this->index->groupingRows($query, $afterFileId);
				foreach ($rows as $row) {
					$key = $this->folderGroup($row['relative_path'], $pathPrefix, $groupDepth);
					$groups[$key] = ($groups[$key] ?? 0) + 1;
					$afterFileId = $row['file_id'];
				}
			} while (count($rows) === 1000);
		} else {
			$groups['all'] = $this->index->countFiltered($query);
		}
		ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
		$indexed = $this->index->countGallery($gallery->getId());
		$limit = $this->policies->get('maxIndexedMedia');
		$lastIndexedAt = $this->index->lastSeenAt($gallery->getId());
		$scan = $this->scans->find((int)$gallery->getId());
		$state = (string)($scan['status'] ?? ($indexed === 0 ? 'unindexed' : ($indexed >= $limit ? 'limit_reached' : 'ready')));
		return [
			'groups' => $groups,
			'indexed' => $indexed,
			'limit' => $limit,
			'limitReached' => $state === 'limit_reached' || $indexed >= $limit,
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
