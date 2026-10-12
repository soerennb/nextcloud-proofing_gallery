<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\CleanupMediaReplacementJob;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/** File moves are reversible; acknowledged replacements are never rolled back. */
final class MediaReplacementService {
	public function __construct(private MediaCleanupService $cleanup, private IJobList $jobs, private LoggerInterface $logger) {
	}

	public function recoverOriginal(Folder $target, string $name, int $stagedId, ?int $expectedId, ?string $expectedEtag): ?File {
		$backupName = '.' . $name . '.replaced-' . $stagedId;
		if (!$target->nodeExists($backupName)) return null;
		$backup = $target->get($backupName);
		if (!$backup instanceof File || ($expectedId !== null && $backup->getId() !== $expectedId)
			|| ($expectedEtag !== null && $backup->getEtag() !== $expectedEtag)) {
			throw new \OCA\ProofingGallery\Exception\UploadConflictException('The replacement backup changed');
		}
		return $backup;
	}

	public function recoverSidecar(Folder $target, string $name, int $stagedId): ?File {
		$sidecarName = pathinfo($name, PATHINFO_FILENAME) . '.xmp';
		foreach (['.' . $sidecarName . '.replaced-' . $stagedId, $sidecarName] as $candidate) {
			if (!$target->nodeExists($candidate)) continue;
			$node = $target->get($candidate);
			if ($node instanceof File) return $node;
		}
		return null;
	}

	/** @param callable(File):void $acknowledge */
	public function replace(string $userId, File $file, ?File $original, ?File $sidecar, Folder $target, string $name, string $stagingName, callable $acknowledge): void {
		$destination = $target->getPath() . '/' . $name;
		$originalPath = $original?->getPath();
		$sidecarPath = $sidecar?->getPath();
		try {
			$backupPath = $target->getPath() . '/.' . $name . '.replaced-' . $file->getId();
			$sidecarBackupPath = $target->getPath() . '/.' . pathinfo($name, PATHINFO_FILENAME) . '.xmp.replaced-' . $file->getId();
			if ($original !== null && $original->getPath() !== $backupPath) $original->move($backupPath);
			if ($sidecar !== null && $sidecar->getPath() !== $sidecarBackupPath) $sidecar->move($sidecarBackupPath);
			$file->move($destination);
		} catch (\Throwable $exception) {
			$this->restore($file, $target->getPath() . '/' . $stagingName);
			if ($original !== null && $originalPath !== null) $this->restore($original, $originalPath);
			if ($sidecar !== null && $sidecarPath !== null) $this->restore($sidecar, $sidecarPath);
			throw new FolderAccessException('The uploaded file could not be committed safely', previous: $exception);
		}
		// A failed receipt leaves the committed file and backups available for recovery.
		$acknowledge($file);
		$this->cleanupAcknowledged($userId, $file->getId(), $original, $sidecar);
	}

	public function cleanupAcknowledged(string $userId, int $fileId, ?File $original, ?File $sidecar): void {
		if ($original === null && $sidecar === null) return;
		$argument = ['userId' => $userId, 'fileId' => $fileId, 'originalId' => $original?->getId(), 'sidecarId' => $sidecar?->getId()];
		try {
			$this->jobs->add(CleanupMediaReplacementJob::class, $argument);
			if ($original !== null) $this->cleanup->purge($original->getId());
			$original?->delete();
			$sidecar?->delete();
			$this->jobs->remove(CleanupMediaReplacementJob::class, $argument);
		} catch (\Throwable $exception) {
			$this->logger->warning('Committed media replacement cleanup needs retry', ['exception' => $exception, 'fileId' => $fileId]);
		}
	}

	public function movePair(Node $node, ?File $sidecar, string $destination, ?string $sidecarDestination): void {
		$originalPath = $node->getPath();
		$sidecarPath = $sidecar?->getPath();
		try {
			$node->move($destination);
			if ($sidecar !== null && $sidecarDestination !== null && $sidecarDestination !== $sidecarPath) $sidecar->move($sidecarDestination);
		} catch (\Throwable $exception) {
			$this->restore($node, $originalPath);
			if ($sidecar !== null && $sidecarPath !== null) $this->restore($sidecar, $sidecarPath);
			throw $exception;
		}
	}

	private function restore(Node $node, string $path): void {
		try { if ($node->getPath() !== $path) $node->move($path); }
		catch (\Throwable $exception) { $this->logger->error('Media move compensation failed', ['fileId' => $node->getId(), 'exception' => $exception]); }
	}
}
