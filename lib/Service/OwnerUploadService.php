<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\MediaItem;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;

final class OwnerUploadService {
	public const CHUNK_SIZE = 5 * 1024 * 1024;
	private const ROOT = 'owner-uploads';

	public function __construct(
		private IAppData $appData,
		private ISecureRandom $random,
		private ITimeFactory $clock,
		private FolderService $folders,
		private PolicyService $policies,
	) {
	}

	/** @return array<string, mixed> */
	public function initiate(
		Gallery $gallery,
		string $ownerUid,
		string $filename,
		string $mimeType,
		int $size,
		string $path,
		string $conflict,
	): array {
		$this->assertGallery($gallery, $ownerUid);
		$filename = $this->safeFilename($filename);
		$path = trim($path, '/');
		if (in_array('..', explode('/', $path), true)) throw new InvalidArgumentException('Invalid upload path');
		if ($size <= 0 || $size > $this->policies->get('maxUploadBytes')) throw new InvalidArgumentException('Upload size is outside the allowed range');
		if (!$this->supportedMime($mimeType)) throw new InvalidArgumentException('Only images, MP4 and WebM files are accepted');
		if (!in_array($conflict, ['fail', 'rename', 'overwrite', 'skip'], true)) throw new InvalidArgumentException('Unknown conflict strategy');

		$id = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$folder = $this->root()->newFolder($id);
		$manifest = [
			'id' => $id,
			'galleryId' => (int)$gallery->getId(),
			'ownerUid' => $ownerUid,
			'filename' => $filename,
			'mimeType' => $mimeType,
			'size' => $size,
			'path' => $path,
			'conflict' => $conflict,
			'createdAt' => $this->clock->getTime(),
			'updatedAt' => $this->clock->getTime(),
		];
		$folder->newFile('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
		$folder->newFolder('chunks');
		return $this->response($folder, $manifest);
	}

	/** @return array<string, mixed> */
	public function status(Gallery $gallery, string $ownerUid, string $uploadId): array {
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		return $this->response($folder, $manifest);
	}

	public function putChunk(Gallery $gallery, string $ownerUid, string $uploadId, int $index, string $content): void {
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		$chunks = (int)ceil((int)$manifest['size'] / self::CHUNK_SIZE);
		if ($index < 0 || $index >= $chunks) throw new InvalidArgumentException('Chunk index is outside the upload');
		$expected = $index === $chunks - 1 ? (int)$manifest['size'] - ($index * self::CHUNK_SIZE) : self::CHUNK_SIZE;
		if (strlen($content) !== $expected) throw new InvalidArgumentException('Upload chunk has an unexpected size');
		$chunkFolder = $folder->getFolder('chunks');
		$name = sprintf('%08d.chunk', $index);
		if ($chunkFolder->fileExists($name)) $chunkFolder->getFile($name)->putContent($content);
		else $chunkFolder->newFile($name, $content);
		$manifest['updatedAt'] = $this->clock->getTime();
		$folder->getFile('manifest.json')->putContent(json_encode($manifest, JSON_THROW_ON_ERROR));
	}

	/** @return array{status: string, item?: MediaItem} */
	public function finalize(Gallery $gallery, string $ownerUid, string $uploadId): array {
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		$expected = (int)ceil((int)$manifest['size'] / self::CHUNK_SIZE);
		if ($this->uploadedChunks($folder) !== range(0, $expected - 1)) throw new InvalidArgumentException('Upload is incomplete');
		$temporaryPath = tempnam(sys_get_temp_dir(), 'proofing-owner-upload-');
		if ($temporaryPath === false) throw new \RuntimeException('Temporary upload file unavailable');
		$target = fopen($temporaryPath, 'wb');
		if ($target === false) throw new \RuntimeException('Temporary upload file unavailable');
		try {
			foreach (range(0, $expected - 1) as $index) {
				$source = $folder->getFolder('chunks')->getFile(sprintf('%08d.chunk', $index))->read();
				if (!is_resource($source)) throw new \RuntimeException('Upload chunk cannot be read');
				stream_copy_to_stream($source, $target);
				fclose($source);
			}
		} finally {
			fclose($target);
		}
		if (filesize($temporaryPath) !== (int)$manifest['size']) {
			@unlink($temporaryPath);
			throw new InvalidArgumentException('Uploaded byte count does not match');
		}
		try {
			$item = $this->folders->uploadMedia(
				$ownerUid,
				$gallery->getFolderId(),
				(string)$manifest['path'],
				(string)$manifest['filename'],
				$temporaryPath,
				(string)$manifest['conflict'],
			);
		} finally {
			@unlink($temporaryPath);
		}
		$folder->delete();
		return $item === null ? ['status' => 'skipped'] : ['status' => 'completed', 'item' => $item];
	}

	private function assertGallery(Gallery $gallery, string $ownerUid): void {
		if ($gallery->getOwnerUid() !== $ownerUid || $gallery->getSourceType() !== 'folder') throw new InvalidArgumentException('Owner uploads require an owned folder gallery');
	}

	/** @return array{ISimpleFolder, array<string, mixed>} */
	private function session(Gallery $gallery, string $ownerUid, string $uploadId): array {
		if (preg_match('/^[A-Za-z0-9]{40}$/', $uploadId) !== 1) throw new InvalidArgumentException('Upload not found');
		try {
			$folder = $this->root()->getFolder($uploadId);
			$manifest = json_decode($folder->getFile('manifest.json')->getContent(), true, flags: JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new InvalidArgumentException('Upload not found');
		}
		if (!is_array($manifest) || (int)($manifest['galleryId'] ?? 0) !== (int)$gallery->getId() || ($manifest['ownerUid'] ?? null) !== $ownerUid) {
			throw new InvalidArgumentException('Upload not found');
		}
		return [$folder, $manifest];
	}

	/** @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	private function response(ISimpleFolder $folder, array $manifest): array {
		return [
			'id' => $manifest['id'],
			'filename' => $manifest['filename'],
			'size' => $manifest['size'],
			'chunkSize' => self::CHUNK_SIZE,
			'chunks' => (int)ceil((int)$manifest['size'] / self::CHUNK_SIZE),
			'uploadedChunks' => $this->uploadedChunks($folder),
		];
	}

	/** @return list<int> */
	private function uploadedChunks(ISimpleFolder $folder): array {
		$indexes = [];
		foreach ($folder->getFolder('chunks')->getDirectoryListing() as $node) {
			if (preg_match('/^(\d{8})\.chunk$/', $node->getName(), $match) === 1) $indexes[] = (int)$match[1];
		}
		sort($indexes);
		return $indexes;
	}

	private function root(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::ROOT);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::ROOT);
		}
	}

	private function safeFilename(string $filename): string {
		$filename = trim(str_replace(["\0", '/', '\\'], '', $filename));
		if ($filename === '' || mb_strlen($filename) > 255) throw new InvalidArgumentException('Invalid upload filename');
		return $filename;
	}

	private function supportedMime(string $mimeType): bool {
		return str_starts_with($mimeType, 'image/') || in_array($mimeType, ['video/mp4', 'video/webm'], true);
	}
}
