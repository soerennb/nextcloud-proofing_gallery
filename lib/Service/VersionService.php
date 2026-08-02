<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\VersionRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;

final class VersionService {
	public function __construct(
		private VersionRepository $repository,
		private IAppData $appData,
		private ISecureRandom $random,
		private ITimeFactory $clock,
		private FolderService $folders,
		private PolicyService $policies,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(Gallery $gallery, int $fileId): array {
		$this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
		return array_map(static fn (array $row): array => [
			'id' => $row['version_id'],
			'filename' => $row['filename'],
			'mimeType' => $row['mime_type'],
			'size' => (int)$row['size'],
			'createdBy' => $row['created_by'],
			'createdAt' => (int)$row['created_at'],
		], $this->repository->list($gallery->getId(), $fileId));
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
		$stream = $file->fopen('rb');
		if (!is_resource($stream)) {
			throw new InvalidArgumentException('The current file could not be archived');
		}
		try {
			$archive = $this->versionFolder($gallery->getId(), $file->getId())->newFile($versionId, $stream);
		} finally {
			fclose($stream);
		}
		try {
			$this->repository->insert($gallery->getId(), $file->getId(), $versionId, $file->getName(), $file->getMimeType(), (int)$file->getSize(), $userId, $this->clock->getTime());
		} catch (\Throwable $exception) {
			$archive->delete();
			throw $exception;
		}
		$this->pruneFile($gallery->getId(), $file->getId());
	}

	public function cleanupExpired(int $limit = 1000): int {
		$before = $this->clock->getTime() - $this->policies->get('versionRetentionDays') * 86400;
		return $this->deleteRows($this->repository->expired($before, $limit));
	}

	private function pruneFile(int $galleryId, int $fileId): void {
		$this->deleteRows($this->repository->excess($galleryId, $fileId, $this->policies->get('maxVersionsPerFile')));
	}

	/** @param list<array<string, mixed>> $rows */
	private function deleteRows(array $rows): int {
		foreach ($rows as $row) {
			try {
				$this->versionFolder((int)$row['gallery_id'], (int)$row['file_id'])->getFile((string)$row['version_id'])->delete();
			} catch (\OCP\Files\NotFoundException) {
			}
		}
		$ids = array_map(static fn (array $row): int => (int)$row['id'], $rows);
		if ($ids === []) return 0;
		return $this->repository->delete($ids);
	}

	private function find(Gallery $gallery, int $fileId, string $versionId): void {
		if (!$this->repository->exists($gallery->getId(), $fileId, $versionId)) {
			throw new InvalidArgumentException('Archived version not found');
		}
	}

	private function supportedUploadMime(string $path): string {
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
		if (!is_string($mime) || (!str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/'))) {
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
