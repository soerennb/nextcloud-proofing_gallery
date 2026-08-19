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
		private MediaTypePolicy $mediaTypes,
		private UploadLockService $locks,
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
		?int $expectedFileId = null,
		?string $expectedEtag = null,
	): array {
		$this->assertGallery($gallery, $ownerUid);
		$filename = $this->safeFilename($filename);
		$path = trim($path, '/');
		if (in_array('..', explode('/', $path), true)) throw new InvalidArgumentException('Invalid upload path');
		if ($size <= 0 || $size > $this->policies->get('maxUploadBytes')) throw new InvalidArgumentException('Upload size is outside the allowed range');
		if (!$this->mediaTypes->supports($mimeType)) throw new InvalidArgumentException('The declared media type is not supported');
		if (!in_array($conflict, ['fail', 'rename', 'overwrite', 'skip'], true)) throw new InvalidArgumentException('Unknown conflict strategy');

		$id = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$folder = $this->root()->newFolder($id);
		$manifest = [
			'id' => $id,
			'galleryId' => (int)$gallery->getId(),
			'folderId' => $gallery->getFolderId(),
			'ownerUid' => $ownerUid,
			'filename' => $filename,
			'mimeType' => $mimeType,
			'size' => $size,
			'path' => $path,
			'conflict' => $conflict,
			'expectedFileId' => $expectedFileId,
			'expectedEtag' => $expectedEtag,
			'state' => 'pending',
			'stagingName' => $this->folders->stagingName($filename, $id),
			'createdAt' => $this->clock->getTime(),
			'updatedAt' => $this->clock->getTime(),
		];
		try {
			$folder->newFile('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
			$folder->newFolder('chunks');
		} catch (\Throwable $exception) {
			$folder->delete();
			throw $exception;
		}
		return $this->response($folder, $manifest);
	}

	/**
	 * @param list<array<string, mixed>> $uploads
	 * @return array{uploads: list<array<string, mixed>>}
	 */
	public function initiateBatch(Gallery $gallery, string $ownerUid, array $uploads): array {
		$this->assertGallery($gallery, $ownerUid);
		if ($uploads === [] || count($uploads) > 1000) throw new InvalidArgumentException('Select between 1 and 1000 files');
		$result = [];
		$created = [];
		try {
			foreach ($uploads as $upload) {
				if (!is_array($upload)) throw new InvalidArgumentException('Invalid upload entry');
				$resumed = $this->resumeBatchEntry($gallery, $ownerUid, $upload);
				if ($resumed !== null) {
					$result[] = $resumed;
					continue;
				}
				$session = $this->initiate(
					$gallery,
					$ownerUid,
					(string)($upload['filename'] ?? ''),
					(string)($upload['mimeType'] ?? ''),
					(int)($upload['size'] ?? 0),
					(string)($upload['path'] ?? ''),
					(string)($upload['conflict'] ?? 'fail'),
					isset($upload['expectedFileId']) ? (int)$upload['expectedFileId'] : null,
					isset($upload['expectedEtag']) && is_string($upload['expectedEtag']) ? $upload['expectedEtag'] : null,
				);
				$created[] = (string)$session['id'];
				$result[] = $session;
			}
		} catch (\Throwable $exception) {
			foreach ($created as $uploadId) {
				try { $this->root()->getFolder($uploadId)->delete(); } catch (\Throwable) {}
			}
			throw $exception;
		}
		return ['uploads' => $result];
	}

	/** @param list<string> $filenames
	 * @return array<string, MediaItem>
	 */
	public function conflicts(Gallery $gallery, string $ownerUid, string $path, array $filenames): array {
		$this->assertGallery($gallery, $ownerUid);
		$path = trim($path, '/');
		if (in_array('..', explode('/', $path), true)) throw new InvalidArgumentException('Invalid upload path');
		if (count($filenames) > 1000) throw new InvalidArgumentException('Select no more than 1000 files');
		return $this->folders->uploadConflicts($ownerUid, $gallery->getFolderId(), $path, $filenames);
	}

	/** @return array<string, mixed> */
	public function updateResolution(Gallery $gallery, string $ownerUid, string $uploadId, string $conflict, ?int $expectedFileId, ?string $expectedEtag): array {
		if (!in_array($conflict, ['fail', 'rename', 'overwrite', 'skip'], true)) throw new InvalidArgumentException('Unknown conflict strategy');
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		$manifest['conflict'] = $conflict;
		$manifest['expectedFileId'] = $expectedFileId;
		$manifest['expectedEtag'] = $expectedEtag;
		$manifest['updatedAt'] = $this->clock->getTime();
		$this->writeManifest($folder, $manifest);
		return $this->response($folder, $manifest);
	}

	/** @return array<string, mixed> */
	public function status(Gallery $gallery, string $ownerUid, string $uploadId): array {
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		return $this->response($folder, $manifest);
	}

	public function putChunk(Gallery $gallery, string $ownerUid, string $uploadId, int $index, string $content): void {
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		if (($manifest['state'] ?? 'pending') !== 'pending') throw new InvalidArgumentException('Upload is not accepting chunks');
		$chunks = (int)ceil((int)$manifest['size'] / self::CHUNK_SIZE);
		if ($index < 0 || $index >= $chunks) throw new InvalidArgumentException('Chunk index is outside the upload');
		$expected = $index === $chunks - 1 ? (int)$manifest['size'] - ($index * self::CHUNK_SIZE) : self::CHUNK_SIZE;
		if (strlen($content) !== $expected) throw new InvalidArgumentException('Upload chunk has an unexpected size');
		$chunkFolder = $folder->getFolder('chunks');
		$name = sprintf('%08d.chunk', $index);
		if ($chunkFolder->fileExists($name)) $chunkFolder->getFile($name)->putContent($content);
		else $chunkFolder->newFile($name, $content);
		$now = $this->clock->getTime();
		if ($index === 0 || $index === $chunks - 1 || $now - (int)($manifest['updatedAt'] ?? 0) >= 60) {
			$manifest['updatedAt'] = $now;
			$this->writeManifest($folder, $manifest);
		}
	}

	/** @param resource $stream
	 * @return array{status: string, item?: array<string, mixed>}
	 */
	public function uploadContent(Gallery $gallery, string $ownerUid, string $uploadId, mixed $stream): array {
		if (!is_resource($stream)) throw new InvalidArgumentException('Upload content could not be read');
		return $this->locks->immediately(
			'proofing-gallery/owner-upload/' . $uploadId,
			'Proofing Gallery owner upload session',
			fn (): array => $this->uploadContentLocked($gallery, $ownerUid, $uploadId, $stream),
		);
	}

	/** @return array{status: string, item?: MediaItem|array<string, mixed>} */
	public function finalize(Gallery $gallery, string $ownerUid, string $uploadId): array {
		return $this->locks->immediately(
			'proofing-gallery/owner-upload/' . $uploadId,
			'Proofing Gallery owner upload session',
			fn (): array => $this->finalizeLocked($gallery, $ownerUid, $uploadId),
		);
	}

	/** @return array{status: string, item?: MediaItem|array<string, mixed>} */
	private function finalizeLocked(Gallery $gallery, string $ownerUid, string $uploadId): array {
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		if (($manifest['state'] ?? 'pending') === 'completed') return $this->completedResult($manifest);
		if (($manifest['state'] ?? 'pending') === 'staged') return $this->commitStaged($folder, $manifest);
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
		$source = fopen($temporaryPath, 'rb');
		if ($source === false) {
			@unlink($temporaryPath);
			throw new \RuntimeException('Temporary upload file unavailable');
		}
		try {
			$staged = $this->folders->stageMedia(
				$ownerUid, $gallery->getFolderId(), (string)$manifest['path'], (string)$manifest['filename'],
				$source, (string)$manifest['mimeType'], $uploadId,
			);
		} finally {
			if (is_resource($source)) fclose($source);
			@unlink($temporaryPath);
		}
		$manifest['state'] = 'staged';
		$manifest['stagingFileId'] = (int)$staged->getId();
		$manifest['stagingName'] = $staged->getName();
		$manifest['updatedAt'] = $this->clock->getTime();
		$this->writeManifest($folder, $manifest);
		return $this->commitStaged($folder, $manifest);
	}

	/** @param resource $stream
	 * @return array{status: string, item?: array<string, mixed>}
	 */
	private function uploadContentLocked(Gallery $gallery, string $ownerUid, string $uploadId, mixed $stream): array {
		[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		$state = (string)($manifest['state'] ?? 'pending');
		if ($state === 'completed') return $this->completedResult($manifest);
		if ($state === 'pending') {
			$staged = $this->folders->stageMedia(
				$ownerUid, $gallery->getFolderId(), (string)$manifest['path'], (string)$manifest['filename'],
				$stream, (string)$manifest['mimeType'], $uploadId,
			);
			if ((int)$staged->getSize() !== (int)$manifest['size']) {
				$staged->delete();
				throw new InvalidArgumentException('Uploaded byte count does not match');
			}
			$manifest['state'] = 'staged';
			$manifest['stagingFileId'] = (int)$staged->getId();
			$manifest['stagingName'] = $staged->getName();
			$manifest['updatedAt'] = $this->clock->getTime();
			$this->writeManifest($folder, $manifest);
		} elseif ($state !== 'staged') {
			throw new InvalidArgumentException('Upload state is invalid');
		}
		return $this->commitStaged($folder, $manifest);
	}

	/** @param array<string, mixed> $manifest
	 * @return array{status: string, item?: array<string, mixed>}
	 */
	private function commitStaged(ISimpleFolder $folder, array $manifest): array {
		$stagingFileId = (int)($manifest['stagingFileId'] ?? 0);
		$stagingName = (string)($manifest['stagingName'] ?? '');
		if ($stagingFileId < 1 || $stagingName === '') throw new InvalidArgumentException('Staged upload is unavailable');
		$item = $this->folders->commitStagedMedia(
			(string)$manifest['ownerUid'], (int)$manifest['folderId'], (string)$manifest['path'], (string)$manifest['filename'],
			$stagingFileId, $stagingName, (string)$manifest['conflict'],
			isset($manifest['expectedFileId']) ? (int)$manifest['expectedFileId'] : null,
			isset($manifest['expectedEtag']) && is_string($manifest['expectedEtag']) ? $manifest['expectedEtag'] : null,
		);
		$result = $item === null ? ['status' => 'skipped'] : ['status' => 'completed', 'item' => $item->jsonSerialize()];
		$manifest['state'] = 'completed';
		$manifest['result'] = $result;
		$manifest['updatedAt'] = $this->clock->getTime();
		$this->writeManifest($folder, $manifest);
		$this->deleteChunks($folder);
		return $result;
	}

	/** @param array<string, mixed> $manifest
	 * @return array{status: string, item?: array<string, mixed>}
	 */
	private function completedResult(array $manifest): array {
		$result = $manifest['result'] ?? null;
		if (!is_array($result) || !in_array($result['status'] ?? null, ['completed', 'skipped'], true)) {
			throw new InvalidArgumentException('Completed upload receipt is invalid');
		}
		return $result;
	}

	/** @param array<string, mixed> $upload
	 * @return ?array<string, mixed>
	 */
	private function resumeBatchEntry(Gallery $gallery, string $ownerUid, array $upload): ?array {
		$uploadId = $upload['uploadId'] ?? null;
		if (!is_string($uploadId) || $uploadId === '') return null;
		try {
			[$folder, $manifest] = $this->session($gallery, $ownerUid, $uploadId);
		} catch (InvalidArgumentException) {
			return null;
		}
		if ((string)($manifest['filename'] ?? '') !== (string)($upload['filename'] ?? '')
			|| (string)($manifest['mimeType'] ?? '') !== (string)($upload['mimeType'] ?? '')
			|| (int)($manifest['size'] ?? 0) !== (int)($upload['size'] ?? 0)
			|| (string)($manifest['path'] ?? '') !== trim((string)($upload['path'] ?? ''), '/')) {
			return null;
		}
		return $this->response($folder, $manifest);
	}

	private function deleteChunks(ISimpleFolder $folder): void {
		try {
			foreach ($folder->getFolder('chunks')->getDirectoryListing() as $chunk) $chunk->delete();
		} catch (\Throwable) {
		}
	}

	/** @param array<string, mixed> $manifest */
	private function writeManifest(ISimpleFolder $folder, array $manifest): void {
		$folder->getFile('manifest.json')->putContent(json_encode($manifest, JSON_THROW_ON_ERROR));
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
		$manifest['folderId'] ??= $gallery->getFolderId();
		$manifest['state'] ??= 'pending';
		return [$folder, $manifest];
	}

	/** @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	private function response(ISimpleFolder $folder, array $manifest): array {
		$response = [
			'id' => $manifest['id'],
			'filename' => $manifest['filename'],
			'size' => $manifest['size'],
			'state' => $manifest['state'] ?? 'pending',
			'chunkSize' => self::CHUNK_SIZE,
			'chunks' => (int)ceil((int)$manifest['size'] / self::CHUNK_SIZE),
			'uploadedChunks' => $this->uploadedChunks($folder),
		];
		if (($manifest['state'] ?? null) === 'completed') $response += $this->completedResult($manifest);
		return $response;
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

}
