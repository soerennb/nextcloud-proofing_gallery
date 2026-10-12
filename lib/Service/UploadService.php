<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Db\UploadRepository;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Security\ISecureRandom;

final class UploadService {
	public const CHUNK_SIZE = 5 * 1024 * 1024;
	use \OCP\AppFramework\Db\TTransactional;

	public function __construct(
		private UploadRepository $repository,
		private ITimeFactory $clock,
		private IAppData $appData,
		private ISecureRandom $random,
		private FolderService $folders,
		private ActivityService $activity,
		private PolicyService $policies,
		private CapabilityPolicyService $capabilities,
		private MediaTypePolicy $mediaTypes,
		private UploadLockService $locks,
		private PrivateGuestInboxService $privateInbox,
		private \OCP\IDBConnection $db,
	) {
	}

	/** @return array<string, mixed> */
	public function initiate(Gallery $gallery, Guest $guest, string $filename, string $mimeType, int $size): array {
		$this->assertEnabled($gallery);
		$filename = $this->safeFilename($filename);
		if ($size <= 0 || $size > $this->policies->get('maxUploadBytes')) {
			throw new InvalidArgumentException('Upload size is outside the allowed range');
		}
		if (!$this->mediaTypes->supports($mimeType)) {
			throw new InvalidArgumentException('The declared media type is not supported');
		}
		$uploadId = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$now = $this->clock->getTime();
		$folder = $this->uploadRoot()->newFolder($uploadId);
		try {
			$this->repository->insert($gallery->getId(), $guest->getId(), $uploadId, $filename, $mimeType, $size, $now);
		} catch (\Throwable $exception) {
			$folder->delete();
			throw $exception;
		}
		return [
			'id' => $uploadId,
			'chunkSize' => self::CHUNK_SIZE,
			'chunks' => (int)ceil($size / self::CHUNK_SIZE),
			'uploadedChunks' => [],
		];
	}

	/** @return array<string, mixed> */
	public function status(Gallery $gallery, Guest $guest, string $uploadId): array {
		$row = $this->upload($gallery, $guest, $uploadId);
		return [
			'id' => $row['upload_id'],
			'filename' => $row['filename'],
			'size' => (int)$row['size'],
			'status' => $row['status'],
			'chunkSize' => self::CHUNK_SIZE,
			'uploadedChunks' => $row['status'] === 'pending' ? $this->uploadedChunks($uploadId) : [],
		];
	}

	public function putChunk(Gallery $gallery, Guest $guest, string $uploadId, int $index, string $content): void {
		$this->locks->wait('proofing-gallery/guest-upload/' . $uploadId, 'Proofing Gallery guest upload chunk', fn () => $this->putChunkLocked($gallery, $guest, $uploadId, $index, $content));
	}

	private function putChunkLocked(Gallery $gallery, Guest $guest, string $uploadId, int $index, string $content): void {
		$row = $this->upload($gallery, $guest, $uploadId);
		if ($row['status'] !== 'pending' || $index < 0 || strlen($content) > self::CHUNK_SIZE) {
			throw new InvalidArgumentException('Invalid upload chunk');
		}
		$expectedChunks = (int)ceil((int)$row['size'] / self::CHUNK_SIZE);
		if ($index >= $expectedChunks) {
			throw new InvalidArgumentException('Chunk index is outside the upload');
		}
		$expectedSize = $index === $expectedChunks - 1
			? (int)$row['size'] - ($index * self::CHUNK_SIZE)
			: self::CHUNK_SIZE;
		if (strlen($content) !== $expectedSize) throw new InvalidArgumentException('Upload chunk has an unexpected size');
		$folder = $this->uploadFolder($uploadId);
		$name = sprintf('%08d.chunk', $index);
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);
		} else {
			$folder->newFile($name, $content);
		}
		$this->touch($uploadId);
	}

	/** @return array<string, mixed> */
	public function finalize(Gallery $gallery, Guest $guest, string $uploadId): array {
		return $this->locks->immediately(
			'proofing-gallery/guest-upload/' . $uploadId,
			'Proofing Gallery guest upload session',
			fn (): array => $this->finalizeLocked($gallery, $guest, $uploadId),
		);
	}

	/** @return array<string, mixed> */
	private function finalizeLocked(Gallery $gallery, Guest $guest, string $uploadId): array {
		$row = $this->upload($gallery, $guest, $uploadId);
		if ($row['status'] !== 'pending') return $this->receipt($row);
		$expected = (int)ceil((int)$row['size'] / self::CHUNK_SIZE);
		if ($this->uploadedChunks($uploadId) !== range(0, $expected - 1)) throw new InvalidArgumentException('Upload is incomplete');
		$stream = tmpfile();
		if ($stream === false) throw new \RuntimeException('Temporary upload stream unavailable');
		try {
			foreach (range(0, $expected - 1) as $index) {
				$chunk = $this->uploadFolder($uploadId)->getFile(sprintf('%08d.chunk', $index))->read();
				if (!is_resource($chunk)) throw new \RuntimeException('Upload chunk cannot be read');
				try { stream_copy_to_stream($chunk, $stream); } finally { fclose($chunk); }
			}
			if (ftell($stream) !== (int)$row['size']) throw new InvalidArgumentException('Uploaded byte count does not match');
			$mime = (new \finfo(FILEINFO_MIME_TYPE))->file(stream_get_meta_data($stream)['uri']);
			if (!is_string($mime) || !$this->mediaTypes->matches((string)$row['mime_type'], $mime)) throw new InvalidArgumentException('Uploaded content does not match the declared media type');
			$stored = $this->privateInbox->store($uploadId, $stream);
			if ((int)$stored->getSize() !== (int)$row['size']) throw new \RuntimeException('Private upload byte count does not match');
			$this->locks->wait('proofing-gallery/guest-upload-inbox/' . $gallery->getId(), 'Proofing Gallery guest upload inbox', function () use ($gallery, $guest, $row, $uploadId, $mime): void {
				$name = $this->inboxFilename($gallery, (string)$row['filename'], $uploadId);
				$this->atomic(function () use ($gallery, $guest, $row, $uploadId, $mime, $name): void {
					$this->repository->finalizePrivate($uploadId, $name, $mime, $this->clock->getTime());
					$this->activity->record($gallery, $guest, 'upload.received', ['uploadId' => $uploadId, 'filename' => $name, 'size' => (int)$row['size']]);
					$this->markResponseReceived($gallery);
				}, $this->db);
			});
		} finally { if (get_resource_type($stream) !== 'Unknown') fclose($stream); }
		$this->privateInbox->cleanupChunks($uploadId);
		return $this->receipt($this->upload($gallery, $guest, $uploadId));
	}

	/** @param array<string, mixed> $row
	 * @return array{id: string, filename: string, status: string} */
	private function receipt(array $row): array {
		return ['id' => (string)$row['upload_id'], 'filename' => (string)$row['filename'], 'status' => (string)$row['status']];
	}


	private function markResponseReceived(Gallery $gallery): void {
		$this->repository->markResponseReceived($gallery->getId(), $this->clock->getTime());
	}

	/** @return list<array<string, mixed>> */
	public function listForGallery(Gallery $gallery): array {
		return array_map($this->presentInboxRow(...), $this->repository->list($gallery->getId()));
	}

	/** @return array{items:list<array<string,mixed>>,total:int,nextCursor:?string} */
	public function pageForGallery(Gallery $gallery, int $limit, ?string $cursor, ScopedCursorCodec $cursors, string $status = ''): array {
		$limit = max(1, min(100, $limit));
		if (!in_array($status, ['', 'pending', 'awaiting_review', 'accepted', 'rejected'], true)) throw new InvalidArgumentException('Invalid upload status');
		$scope = 'gallery-inbox:' . $gallery->getId() . ':' . $status;
		$rows = $this->repository->page($gallery->getId(), $cursors->decode($cursor, $scope), $limit + 1, $status);
		$hasMore = count($rows) > $limit;
		if ($hasMore) array_pop($rows);
		$last = $rows === [] ? null : $rows[array_key_last($rows)];
		return ['items' => array_map($this->presentInboxRow(...), $rows), 'total' => $this->repository->countGallery($gallery->getId(), $status), 'nextCursor' => $hasMore && $last !== null ? $cursors->encode($scope, (int)$last['id']) : null];
	}

	public function moderate(Gallery $gallery, string $uploadId, bool $accept): void {
		$this->locks->wait('proofing-gallery/guest-upload/' . $uploadId, 'Proofing Gallery guest upload moderation', fn () => $this->moderateLocked($gallery, $uploadId, $accept));
	}

	private function moderateLocked(Gallery $gallery, string $uploadId, bool $accept): void {
		$row = $this->uploadForGallery($gallery, $uploadId);
		$status = $accept ? 'accepted' : 'rejected';
		if ($row['status'] === $status) { $this->privateInbox->cleanup(); return; }
		if ($row['status'] !== 'awaiting_review' || !is_string($row['private_key'] ?? null)) throw new InvalidArgumentException('Upload is not awaiting review');
		if (!$accept) {
			if ((int)($row['staging_file_id'] ?? 0) !== 0) throw new InvalidArgumentException('Resume the interrupted acceptance before changing its decision');
			$this->atomic(function () use ($gallery, $uploadId): void {
				if (!$this->repository->moderate($uploadId, 'rejected', $this->clock->getTime())) throw new \RuntimeException('Upload moderation state changed concurrently');
				$this->activity->record($gallery, null, 'upload.rejected', ['uploadId' => $uploadId]);
			}, $this->db);
		} else {
			$stagingId = (int)($row['staging_file_id'] ?? 0);
			$name = (string)$row['filename'];
			$stagingName = $this->folders->stagingName($name, $uploadId);
			if ($stagingId === 0) {
				$stream = $this->privateInbox->read((string)$row['private_key'])->read();
				if (!is_resource($stream)) throw new \RuntimeException('Private inbox payload cannot be read');
				try { $staged = $this->folders->stageMedia($gallery->getOwnerUid(), $gallery->getFolderId(), '', $name, $stream, (string)$row['mime_type'], $uploadId); }
				finally { if (get_resource_type($stream) !== 'Unknown') fclose($stream); }
				$stagingId = (int)$staged->getId();
				$this->repository->stageAcceptance($uploadId, $stagingId);
			}
			$this->folders->commitStagedMedia($gallery->getOwnerUid(), $gallery->getFolderId(), '', $name, $stagingId, $stagingName, 'rename', acknowledge: function (?\OCA\ProofingGallery\Dto\MediaItem $item) use ($gallery, $uploadId): void {
				if ($item === null) throw new \RuntimeException('Inbox acceptance cannot skip its file');
				$this->atomic(function () use ($gallery, $uploadId, $item): void {
					$this->repository->accept($uploadId, $item->id, $item->name, $this->clock->getTime());
					$this->activity->record($gallery, null, 'upload.accepted', ['uploadId' => $uploadId]);
				}, $this->db);
			});
		}
		$this->privateInbox->cleanup();
	}

	private function assertEnabled(Gallery $gallery): void {
		$this->capabilities->assertFeature('guestUploads');
		if ($gallery->getSourceType() === 'collection') {
			throw new InvalidArgumentException('Guest uploads are unavailable for collections');
		}
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		if (!$settings->delivery->guestUploads) {
			throw new InvalidArgumentException('Guest uploads are disabled');
		}
	}

	/** @return array<string, mixed> */
	private function upload(Gallery $gallery, Guest $guest, string $uploadId): array {
		$row = $this->repository->find($gallery->getId(), $uploadId, $guest->getId());
		if ($row === null) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('Upload not found');
		}
		return $row;
	}

	/** @return array<string, mixed> */
	private function uploadForGallery(Gallery $gallery, string $uploadId): array {
		$row = $this->repository->find($gallery->getId(), $uploadId);
		if ($row === null) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('Upload not found');
		}
		return $row;
	}

	/** @return list<int> */
	private function uploadedChunks(string $uploadId): array {
		$chunks = [];
		foreach ($this->uploadFolder($uploadId)->getDirectoryListing() as $file) {
			if (preg_match('/^(\d{8})\.chunk$/', $file->getName(), $matches) === 1) {
				$chunks[] = (int)$matches[1];
			}
		}
		sort($chunks);
		return $chunks;
	}

	private function touch(string $uploadId): void {
		$this->repository->touch($uploadId, $this->clock->getTime());
	}

	private function uploadRoot(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder('guest-uploads');
		} catch (\OCP\Files\NotFoundException) {
			return $this->appData->newFolder('guest-uploads');
		}
	}

	private function uploadFolder(string $uploadId): \OCP\Files\SimpleFS\ISimpleFolder {
		return $this->uploadRoot()->getFolder($uploadId);
	}

	private function inboxFilename(Gallery $gallery, string $filename, string $uploadId): string {
		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		$stem = pathinfo($filename, PATHINFO_FILENAME);
		$name = $filename;
		for ($copy = 2; $copy < 10000; $copy++) {
			if (!$this->repository->nameTaken((int)$gallery->getId(), $name, $uploadId)) return $name;
			$name = sprintf('%s (%d)%s', $stem, $copy, $extension === '' ? '' : '.' . $extension);
		}
		throw new InvalidArgumentException('Could not allocate a conflict-free filename');
	}

	/** @param array<string, mixed> $row
	 * @return array<string, mixed> */
	private function presentInboxRow(array $row): array {
		unset($row['private_key'], $row['legacy_file_id'], $row['staging_file_id'], $row['chunks_cleaned']);
		return $row;
	}

	private function safeFilename(string $filename): string {
		$filename = trim(str_replace(["\0", '/', '\\'], '-', $filename));
		if ($filename === '' || mb_strlen($filename) > 240 || str_starts_with($filename, '.')) {
			throw new InvalidArgumentException('Invalid filename');
		}
		return $filename;
	}

}
