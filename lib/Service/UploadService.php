<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

final class UploadService {
	public const CHUNK_SIZE = 5 * 1024 * 1024;
	private const INBOX_NAME = '.proofing-gallery-inbox';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $clock,
		private IAppData $appData,
		private ISecureRandom $random,
		private FolderService $folders,
		private ActivityService $activity,
		private PolicyService $policies,
	) {
	}

	/** @return array<string, mixed> */
	public function initiate(Gallery $gallery, Guest $guest, string $filename, string $mimeType, int $size): array {
		$this->assertEnabled($gallery);
		$filename = $this->safeFilename($filename);
		if ($size <= 0 || $size > $this->policies->get('maxUploadBytes')) {
			throw new InvalidArgumentException('Upload size is outside the allowed range');
		}
		if (!$this->supportedMime($mimeType)) {
			throw new InvalidArgumentException('Only images, MP4 and WebM files are accepted');
		}
		$uploadId = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_uploads')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT),
			'upload_id' => $qb->createNamedParameter($uploadId),
			'file_id' => $qb->createNamedParameter(null),
			'filename' => $qb->createNamedParameter($filename),
			'mime_type' => $qb->createNamedParameter($mimeType),
			'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter('pending'),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$this->uploadRoot()->newFolder($uploadId);
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
		if (!$file instanceof File || !$this->supportedMime($file->getMimeType())) {
			$file->delete();
			throw new InvalidArgumentException('Uploaded file content is unsupported');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')
			->set('file_id', $qb->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT))
			->set('filename', $qb->createNamedParameter($filename))
			->set('mime_type', $qb->createNamedParameter($file->getMimeType()))
			->set('status', $qb->createNamedParameter('awaiting_review'))
			->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->executeStatement();
		$this->uploadFolder($uploadId)->delete();
		$this->activity->record($gallery, $guest, 'upload.received', [
			'uploadId' => $uploadId,
			'filename' => $filename,
			'size' => (int)$row['size'],
		]);
		return ['id' => $uploadId, 'filename' => $filename, 'status' => 'awaiting_review'];
	}

	/** @return list<array<string, mixed>> */
	public function listForGallery(Gallery $gallery): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.*', 'g.display_name')
			->from('proofing_uploads', 'u')
			->leftJoin('u', 'proofing_guests', 'g', $qb->expr()->eq('u.guest_id', 'g.id'))
			->where($qb->expr()->eq('u.gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->orderBy('u.created_at', 'DESC');
		return $qb->executeQuery()->fetchAllAssociative();
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
			$file->move($target->getPath() . '/' . $name);
			$status = 'accepted';
		} else {
			$file->delete();
			$status = 'rejected';
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->executeStatement();
		$this->activity->record($gallery, null, 'upload.' . $status, ['uploadId' => $uploadId]);
	}

	private function assertEnabled(Gallery $gallery): void {
		if ($gallery->getSourceType() === 'collection') {
			throw new InvalidArgumentException('Guest uploads are unavailable for collections');
		}
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		if (!$settings->allowGuestUploads) {
			throw new InvalidArgumentException('Guest uploads are disabled');
		}
	}

	/** @return array<string, mixed> */
	private function upload(Gallery $gallery, Guest $guest, string $uploadId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_uploads')
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			throw new InvalidArgumentException('Upload not found');
		}
		return $row;
	}

	/** @return array<string, mixed> */
	private function uploadForGallery(Gallery $gallery, string $uploadId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_uploads')
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
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
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')
			->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->executeStatement();
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

	private function supportedMime(string $mimeType): bool {
		return str_starts_with($mimeType, 'image/') || in_array($mimeType, ['video/mp4', 'video/webm'], true);
	}

}
