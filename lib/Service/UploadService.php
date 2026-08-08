<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Db\UploadRepository;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Security\ISecureRandom;
use OCP\Lock\ILockingProvider;

final class UploadService {
	public const CHUNK_SIZE = 5 * 1024 * 1024;
	private const INBOX_NAME = '.proofing-gallery-inbox';

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
		private ILockingProvider $locks,
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
			'uploadedChunks' => $this->uploadedChunks($uploadId),
		];
	}

	public function putChunk(Gallery $gallery, Guest $guest, string $uploadId, int $index, string $content): void {
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
		$lock = 'proofing-gallery/guest-upload-gallery/' . $gallery->getId();
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Proofing Gallery guest upload');
		try {
			return $this->finalizeLocked($gallery, $guest, $uploadId);
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return array<string, mixed> */
	private function finalizeLocked(Gallery $gallery, Guest $guest, string $uploadId): array {
		$row = $this->upload($gallery, $guest, $uploadId);
		if ($row['status'] !== 'pending') {
			throw new InvalidArgumentException('Upload is not pending');
		}
		$expectedChunks = (int)ceil((int)$row['size'] / self::CHUNK_SIZE);
		$chunks = $this->uploadedChunks($uploadId);
		if ($chunks !== range(0, $expectedChunks - 1)) {
			throw new InvalidArgumentException('Upload is incomplete');
		}
		$stream = tmpfile();
		if ($stream === false) {
			throw new \RuntimeException('Temporary upload stream unavailable');
		}
		foreach ($chunks as $index) {
			$chunkStream = $this->uploadFolder($uploadId)->getFile(sprintf('%08d.chunk', $index))->read();
			if (!is_resource($chunkStream)) {
				throw new \RuntimeException('Upload chunk cannot be read');
			}
			stream_copy_to_stream($chunkStream, $stream);
			fclose($chunkStream);
		}
		if (ftell($stream) !== (int)$row['size']) {
			fclose($stream);
			throw new InvalidArgumentException('Uploaded byte count does not match');
		}
		rewind($stream);
		$inbox = $this->inbox($gallery);
		$filename = $this->conflictFreeName($inbox, (string)$row['filename']);
		$file = $inbox->newFile($filename, $stream);
		if (is_resource($stream)) {
			fclose($stream);
		}
		if (!$file instanceof File || !$this->mediaTypes->matches((string)$row['mime_type'], $file)) {
			$file->delete();
			throw new InvalidArgumentException('Uploaded content does not match the declared media type');
		}
		try {
			$this->repository->finalize($uploadId, $file->getId(), $filename, $file->getMimeType(), $this->clock->getTime());
		} catch (\Throwable $exception) {
			$file->delete();
			throw $exception;
		}
		$this->uploadFolder($uploadId)->delete();
		$this->activity->record($gallery, $guest, 'upload.received', [
			'uploadId' => $uploadId,
			'filename' => $filename,
			'size' => (int)$row['size'],
		]);
		$this->markResponseReceived($gallery);
		return ['id' => $uploadId, 'filename' => $filename, 'status' => 'awaiting_review'];
	}

	private function markResponseReceived(Gallery $gallery): void {
		$this->repository->markResponseReceived($gallery->getId(), $this->clock->getTime());
	}

	/** @return list<array<string, mixed>> */
	public function listForGallery(Gallery $gallery): array {
		return $this->repository->list($gallery->getId());
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
		return ['items' => $rows, 'total' => $this->repository->countGallery($gallery->getId(), $status), 'nextCursor' => $hasMore && $last !== null ? $cursors->encode($scope, (int)$last['id']) : null];
	}

	public function moderate(Gallery $gallery, string $uploadId, bool $accept): void {
		$row = $this->uploadForGallery($gallery, $uploadId);
		if ($row['status'] !== 'awaiting_review' || $row['file_id'] === null) {
			throw new InvalidArgumentException('Upload is not awaiting review');
		}
		$file = $this->inbox($gallery)->getById((int)$row['file_id'])[0] ?? null;
		if (!$file instanceof File) {
			throw new InvalidArgumentException('Inbox file no longer exists');
		}
		if ($accept) {
			$target = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
			$name = $this->conflictFreeName($target, $file->getName());
			$originalPath = $file->getPath();
			$file->move($target->getPath() . '/' . $name);
			$status = 'accepted';
			try {
				if (!$this->repository->moderate($uploadId, $status, $this->clock->getTime())) throw new \RuntimeException('Upload moderation state changed concurrently');
			} catch (\Throwable $exception) {
				try { $file->move($originalPath); } catch (\Throwable) {}
				throw $exception;
			}
		} else {
			$status = 'rejected';
			$originalPath = $file->getPath();
			$quarantinePath = $file->getParent()->getPath() . '/.rejected-' . $uploadId;
			$file->move($quarantinePath);
			try {
				if (!$this->repository->moderate($uploadId, $status, $this->clock->getTime())) throw new \RuntimeException('Upload moderation state changed concurrently');
			} catch (\Throwable $exception) {
				try { $file->move($originalPath); } catch (\Throwable) {}
				throw $exception;
			}
			$file->delete();
		}
		$this->activity->record($gallery, null, 'upload.' . $status, ['uploadId' => $uploadId]);
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
			throw new InvalidArgumentException('Upload not found');
		}
		return $row;
	}

	/** @return array<string, mixed> */
	private function uploadForGallery(Gallery $gallery, string $uploadId): array {
		$row = $this->repository->find($gallery->getId(), $uploadId);
		if ($row === null) {
			throw new InvalidArgumentException('Upload not found');
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

	private function inbox(Gallery $gallery): Folder {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		try {
			$folder = $root->get(self::INBOX_NAME);
			if (!$folder instanceof Folder) {
				throw new \RuntimeException('Reserved inbox path is not a folder');
			}
			return $folder;
		} catch (\OCP\Files\NotFoundException) {
			return $root->newFolder(self::INBOX_NAME);
		}
	}

	private function conflictFreeName(Folder $folder, string $filename): string {
		if (!$folder->nodeExists($filename)) {
			return $filename;
		}
		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		$stem = pathinfo($filename, PATHINFO_FILENAME);
		for ($copy = 2; $copy < 10000; $copy++) {
			$candidate = sprintf('%s (%d)%s', $stem, $copy, $extension === '' ? '' : '.' . $extension);
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		throw new InvalidArgumentException('Could not allocate a conflict-free filename');
	}

	private function safeFilename(string $filename): string {
		$filename = trim(str_replace(["\0", '/', '\\'], '-', $filename));
		if ($filename === '' || mb_strlen($filename) > 240 || str_starts_with($filename, '.')) {
			throw new InvalidArgumentException('Invalid filename');
		}
		return $filename;
	}

}
