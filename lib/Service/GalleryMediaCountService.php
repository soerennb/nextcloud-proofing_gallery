<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\RebuildGalleryMediaCountsJob;
use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/** Completed snapshots are never replaced by partial or superseded generations. */
final class GalleryMediaCountService {
	public function __construct(
		private GalleryMediaCountRepository $counts,
		private FolderService $folders,
		private FolderMediaReader $reader,
		private CollectionService $collections,
		private CollectionRepository $members,
		private ITimeFactory $clock,
		private IJobList $jobs,
		private ILockingProvider $locks,
		private IDBConnection $db,
		private GalleryMapper $galleries,
	) {
	}

	/** @param array<string, mixed>|null $row
	 * @return array{total: int, imageCount: ?int, videoCount: ?int, countState: string, countedAt: ?int}
	 */
	public static function present(?array $row): array {
		$available = ($row['state'] ?? '') !== 'unavailable';
		$images = $available && isset($row['image_count']) ? (int)$row['image_count'] : null;
		$videos = $available && isset($row['video_count']) ? (int)$row['video_count'] : null;
		return ['total' => ($images ?? 0) + ($videos ?? 0), 'imageCount' => $images, 'videoCount' => $videos, 'countState' => (string)($row['state'] ?? 'pending'), 'countedAt' => isset($row['counted_at']) ? (int)$row['counted_at'] : null];
	}

	/** @return array{total: int, imageCount: ?int, videoCount: ?int, countState: string, countedAt: ?int} */
	public function summary(int $id): array {
		$row = $this->counts->find($id);
		if ($row === null || in_array($row['state'], ['pending', 'updating', 'error'], true)) $this->queue($id);
		return self::present($row);
	}

	public function queue(int $id): void {
		$this->counts->ensure($id, $this->clock->getTime());
		$this->jobs->add(RebuildGalleryMediaCountsJob::class, ['galleryId' => $id]);
	}

	/** @return bool Whether this generation is complete. */
	public function run(Gallery $gallery): bool {
		$id = (int)$gallery->getId();
		$lock = 'proofing-gallery/media-counts/' . $id;
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Proofing Gallery media counts');
		try { return $this->runLocked($gallery); }
		finally { $this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE); }
	}

	private function runLocked(Gallery $gallery): bool {
		$id = (int)$gallery->getId();
		$now = $this->clock->getTime();
		$this->counts->ensure($id, $now);
		// Read the epoch before refreshing the source: a rebind between these
		// reads must either use its new source or fail conditional publication.
		$row = $this->counts->find($id);
		if ($row === null) throw new \RuntimeException('Media count could not be initialized');
		$gallery = $this->galleries->find($id);
		$root = $gallery->getSourceType() === 'folder' ? $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()) : null;
		$signature = hash('sha256', $gallery->getSourceType() . ':' . $gallery->getFolderId() . ':' . ($root?->getEtag() ?? $this->members->revision($id)));
		if ($row['state'] === 'ready' && hash_equals((string)$row['source_signature'], $signature)) return true;
		$this->db->beginTransaction();
		try {
			if ($row['generation'] === '' || (int)$row['scan_revision'] !== (int)$row['revision'] || !hash_equals((string)$row['source_signature'], $signature)) {
				$this->counts->clearQueue($id);
				$row = [...$row, 'generation' => bin2hex(random_bytes(16)), 'scan_revision' => (int)$row['revision'], 'source_signature' => $signature, 'working_images' => 0, 'working_videos' => 0, 'working_witness' => null, 'collection_cursor' => 0];
				if ($root !== null) {
					$this->counts->enqueue($id, $row['generation'], (int)$root->getId(), '');
					foreach ($this->reader->mountPaths($root) as $path) {
						try { $node = $root->get($path); if ($node->isReadable()) $this->counts->enqueue($id, $row['generation'], (int)$node->getId(), $path); }
						catch (NotFoundException|NotPermittedException) {}
					}
				}
			}
			$images = (int)$row['working_images']; $videos = (int)$row['working_videos'];
			$witness = $row['working_witness'] === null ? null : (int)$row['working_witness'];
			$cursor = (int)$row['collection_cursor'];
			if ($root !== null) {
				$budget = 500;
				while ($budget > 1 && ($folder = $this->counts->next($id, (string)$row['generation'])) !== null) {
					$budget--;
					try { $page = $this->reader->page($root, (string)$folder['relative_path'], (int)$folder['after_file_id'], $budget); }
					catch (NotFoundException|NotPermittedException) { $this->counts->advance((int)$folder['id'], 0, true); continue; }
					$budget -= $page['examined'];
					foreach ($page['items'] as $node) {
						$isFile = $node instanceof File;
						// A file mount is already the current queued node.
						$isCurrent = $isFile && (int)$folder['file_id'] === (int)$node->getId();
						if (!$isCurrent && !$this->counts->enqueue($id, (string)$row['generation'], (int)$node->getId(), $this->reader->relativePath($root, $node), $isFile)) continue;
						if (!$isFile) continue;
						if (str_starts_with($node->getMimeType(), 'video/')) $videos++; else $images++;
						$witness ??= (int)$node->getId();
					}
					$this->counts->advance((int)$folder['id'], $page['after'], $page['done']);
				}
				$complete = $this->counts->next($id, (string)$row['generation']) === null;
			} else {
				$items = $this->members->page($id, $cursor, 500);
				foreach ($items as $item) {
					$cursor = (int)$item['id'];
					try {
						$file = $this->collections->resolveMedia($gallery, (int)$item['file_id']);
						if (str_starts_with($file->getMimeType(), 'video/')) $videos++; else $images++;
						$witness ??= (int)$file->getId();
					} catch (\OCA\ProofingGallery\Exception\FolderAccessException|\OCP\AppFramework\Db\DoesNotExistException|NotFoundException) {}
				}
				$complete = count($items) < 500;
			}
			$this->counts->update($id, ['state' => 'updating', 'generation' => (string)$row['generation'], 'scan_revision' => (int)$row['scan_revision'], 'source_signature' => $signature, 'working_images' => $images, 'working_videos' => $videos, 'working_witness' => $witness, 'collection_cursor' => $cursor, 'updated_at' => $now]);
			if ($complete) {
				$complete = $this->counts->publish($id, (string)$row['generation'], (int)$row['scan_revision'], $images, $videos, $witness, $now);
				if ($complete) $this->counts->clearQueue($id);
			}
			$this->db->commit();
			return $complete;
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
	}

	public function hasMedia(Gallery $gallery): bool {
		$row = $this->counts->find((int)$gallery->getId());
		foreach (['working_witness', 'witness_file_id'] as $column) {
			if (!isset($row[$column])) continue;
			try {
				if ($gallery->getSourceType() === 'collection') $this->collections->resolveMedia($gallery, (int)$row[$column]);
				else {
					$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
					$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), (int)$row[$column]);
					if (!$this->reader->isVisibleMedia($root, $file)) continue;
				}
				return true;
			} catch (\OCA\ProofingGallery\Exception\FolderAccessException|\OCP\AppFramework\Db\DoesNotExistException|NotFoundException) {}
		}
		if ($gallery->getSourceType() === 'folder') return $this->reader->hasMedia($this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()));
		foreach ($this->members->page((int)$gallery->getId(), 0, 500) as $item) {
			try { $this->collections->resolveMedia($gallery, (int)$item['file_id']); return true; }
			catch (\OCA\ProofingGallery\Exception\FolderAccessException|\OCP\AppFramework\Db\DoesNotExistException|NotFoundException) {}
		}
		return false;
	}
}
