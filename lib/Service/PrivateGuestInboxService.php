<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\UploadRepository;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/** Unapproved payloads never reside beneath a native public-share root. */
final class PrivateGuestInboxService {
	public function __construct(private IAppData $appData, private IRootFolder $root, private UploadRepository $uploads, private UploadLockService $locks, private LoggerInterface $logger) {
	}

	/** SimpleFS may close the supplied stream after writing.
	 * @param resource $stream */
	public function store(string $uploadId, mixed $stream): ISimpleFile {
		if (!is_resource($stream) || preg_match('/^[A-Za-z0-9]{40}$/D', $uploadId) !== 1) throw new \InvalidArgumentException('Invalid private upload');
		$folder = $this->folder();
		$file = $folder->fileExists($uploadId) ? $folder->getFile($uploadId) : $folder->newFile($uploadId);
		rewind($stream);
		$file->putContent($stream);
		return $file;
	}

	public function read(string $key): ISimpleFile {
		if (preg_match('/^[A-Za-z0-9]{40}$/D', $key) !== 1) throw new \InvalidArgumentException('Invalid private upload');
		return $this->folder()->getFile($key);
	}

	public function delete(string $key): void {
		try { $this->read($key)->delete(); } catch (NotFoundException) {}
	}

	public function cleanupChunks(string $id): bool {
		try {
			try { $this->appData->getFolder('guest-uploads')->getFolder($id)->delete(); } catch (NotFoundException) {}
			$this->uploads->markChunksCleaned($id);
			return true;
		} catch (\Throwable $error) {
			$this->logger->warning('Guest upload chunk cleanup needs retry', ['uploadId' => $id, 'exception' => $error]);
			return false;
		}
	}

	/** A failed cleanup retains its database receipt for the next maintenance run. */
	public function cleanup(int $limit = 100): int {
		$cleaned = 0;
		foreach ($this->uploads->chunkCleanupIds($limit) as $id) if ($this->cleanupChunks($id)) $cleaned++;
		foreach ($this->uploads->privateCleanupRows($limit) as $row) {
			try {
				$this->delete((string)$row['private_key']);
				$this->uploads->clearPrivateKey((string)$row['upload_id']);
				$cleaned++;
			} catch (\Throwable $error) {
				$this->logger->warning('Guest inbox cleanup needs retry', ['uploadId' => $row['upload_id'], 'exception' => $error]);
			}
		}
		return $cleaned;
	}

	/** Runs under upgrade maintenance; receipts make every batch restartable. */
	public function migrateBatch(int $limit = 100): int {
		$rows = $this->uploads->legacyInboxRows($limit);
		foreach ($rows as $row) {
			$this->locks->wait('proofing-gallery/guest-upload/' . $row['upload_id'], 'Proofing Gallery guest inbox migration', function () use ($row): void {
				$id = (int)($row['legacy_file_id'] ?? $row['file_id']);
				$user = $this->root->getUserFolder((string)$row['owner_uid']);
				$file = $user->getById($id)[0] ?? null;
				if ($file !== null && (!$file instanceof File || !str_contains($file->getPath(), '/.proofing-gallery-inbox/'))) throw new \RuntimeException('Legacy inbox reference is outside its reserved folder');
				if ($row['status'] === 'rejected' || ($file === null && $row['private_key'] === null)) {
					$file?->delete();
					$this->uploads->retireLegacy((string)$row['upload_id']);
					return;
				}
				if ($row['private_key'] === null) {
					if (!$file instanceof File) throw new \RuntimeException('Legacy inbox payload is missing');
					$stream = $file->fopen('rb');
					if (!is_resource($stream)) throw new \RuntimeException('Legacy inbox payload cannot be read');
					try { $this->store((string)$row['upload_id'], $stream); } finally { if (get_resource_type($stream) !== 'Unknown') fclose($stream); }
					$this->uploads->privatizeLegacy((string)$row['upload_id'], $id);
				}
				$file?->delete();
				$this->uploads->clearLegacyFile((string)$row['upload_id']);
			});
		}
		return count($rows);
	}

	private function folder(): ISimpleFolder {
		try { return $this->appData->getFolder('guest-inbox'); }
		catch (NotFoundException) { return $this->appData->newFolder('guest-inbox'); }
	}
}
