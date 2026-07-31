<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

final class VersionService {
	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
		private ISecureRandom $random,
		private ITimeFactory $clock,
		private FolderService $folders,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(Gallery $gallery, int $fileId): array {
		$this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('version_id', 'filename', 'mime_type', 'size', 'created_by', 'created_at')
			->from('proofing_versions')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return array_map(static fn (array $row): array => [
			'id' => $row['version_id'],
			'filename' => $row['filename'],
			'mimeType' => $row['mime_type'],
			'size' => (int)$row['size'],
			'createdBy' => $row['created_by'],
			'createdAt' => (int)$row['created_at'],
		], $qb->executeQuery()->fetchAllAssociative());
	}

	public function replace(Gallery $gallery, int $fileId, string $temporaryPath, string $userId): void {
		$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
		$mime = $this->supportedUploadMime($temporaryPath);
		if ($mime !== $file->getMimeType()) {
			throw new InvalidArgumentException('A replacement must use the same file type as the current media');
		}
		$this->snapshot($gallery, $file, $userId);
		$stream = fopen($temporaryPath, 'rb');
		if ($stream === false) {
			throw new InvalidArgumentException('The replacement file could not be read');
		}
		try {
			$file->putContent($stream);
		} finally {
			fclose($stream);
		}
	}

	public function restore(Gallery $gallery, int $fileId, string $versionId, string $userId): void {
		$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
		$this->find($gallery, $fileId, $versionId);
		$this->snapshot($gallery, $file, $userId);
		$stream = $this->versionFolder($gallery->getId(), $fileId)->getFile($versionId)->read();
		if (!is_resource($stream)) {
			throw new InvalidArgumentException('The archived version could not be read');
		}
		try {
			$file->putContent($stream);
		} finally {
			fclose($stream);
		}
	}

	private function snapshot(Gallery $gallery, File $file, string $userId): void {
		$versionId = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$stream = $file->read();
		if (!is_resource($stream)) {
			throw new InvalidArgumentException('The current file could not be archived');
		}
		try {
			$archive = $this->versionFolder($gallery->getId(), $file->getId())->newFile($versionId, $stream);
		} finally {
			fclose($stream);
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('proofing_versions')->values([
				'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
				'file_id' => $qb->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT),
				'version_id' => $qb->createNamedParameter($versionId),
				'filename' => $qb->createNamedParameter($file->getName()),
				'mime_type' => $qb->createNamedParameter($file->getMimeType()),
				'size' => $qb->createNamedParameter((int)$file->getSize(), IQueryBuilder::PARAM_INT),
				'created_by' => $qb->createNamedParameter($userId),
				'created_at' => $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (\Throwable $exception) {
			$archive->delete();
			throw $exception;
		}
	}

	/** @return array<string, mixed> */
	private function find(Gallery $gallery, int $fileId, string $versionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_versions')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId)));
		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false) {
			throw new InvalidArgumentException('Archived version not found');
		}
		return $row;
	}

	private function supportedUploadMime(string $path): string {
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
		if (!is_string($mime) || (!str_starts_with($mime, 'image/') && !in_array($mime, ['video/mp4', 'video/webm'], true))) {
			throw new InvalidArgumentException('Only images, MP4 and WebM files are accepted');
		}
		return $mime;
	}

	private function versionFolder(int $galleryId, int $fileId): ISimpleFolder {
		$root = $this->folder($this->appData, 'versions');
		$gallery = $this->folder($root, (string)$galleryId);
		return $this->folder($gallery, (string)$fileId);
	}

	private function folder(IAppData|ISimpleFolder $parent, string $name): ISimpleFolder {
		try {
			return $parent->getFolder($name);
		} catch (\OCP\Files\NotFoundException) {
			return $parent->newFolder($name);
		}
	}
}
