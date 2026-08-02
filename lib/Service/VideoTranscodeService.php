<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\TranscodeVideoJob;
use OCA\ProofingGallery\Db\VideoDerivativeRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Lock\ILockingProvider;

final class VideoTranscodeService {
	public const PROFILE = 'web-h264';

	public function __construct(
		private VideoDerivativeRepository $repository,
		private PolicyService $policies,
		private IJobList $jobs,
		private ITimeFactory $clock,
		private IRootFolder $rootFolder,
		private IAppData $appData,
		private VideoCommandRunner $commands,
		private ILockingProvider $locks,
	) {
	}

	/** @return array{state: string, playable: bool} */
	public function request(string $ownerUid, File $file): array {
		if (!str_starts_with($file->getMimeType(), 'video/')) return ['state' => 'source', 'playable' => false];
		$sourcePlayable = in_array($file->getMimeType(), ['video/mp4', 'video/webm'], true);
		if (!$this->policies->videoSettings()['enabled']) return ['state' => 'disabled', 'playable' => $sourcePlayable];
		$previous = $this->repository->find($ownerUid, (int)$file->getId());
		$queued = $this->repository->enqueue($ownerUid, (int)$file->getId(), $file->getEtag(), $this->clock->getTime());
		$row = $this->repository->find($ownerUid, (int)$file->getId());
		if ($queued && $row !== null) {
			if ($previous !== null && (string)$previous['source_etag'] !== $file->getEtag()) {
				$this->deleteStored($previous['storage_key'] ?? null);
				$this->deleteStored($previous['poster_key'] ?? null);
			}
			$this->queue($ownerUid, (int)$file->getId(), $file->getEtag());
		}
		return ['state' => (string)($row['status'] ?? 'pending'), 'playable' => $sourcePlayable || ($row['status'] ?? '') === 'ready'];
	}

	public function derivative(string $ownerUid, File $source, bool $poster = false): ?ISimpleFile {
		$row = $this->repository->find($ownerUid, (int)$source->getId());
		if ($row === null || $row['status'] !== 'ready' || $row['source_etag'] !== $source->getEtag()) return null;
		$key = $poster ? $row['poster_key'] : $row['storage_key'];
		if (!is_string($key) || $key === '') return null;
		try {
			return $this->folder()->getFile($key);
		} catch (\OCP\Files\NotFoundException) {
			return null;
		}
	}

	public function process(string $ownerUid, int $fileId, string $etag): void {
		$row = $this->repository->find($ownerUid, $fileId);
		if ($row === null || (string)$row['source_etag'] !== $etag) return;
		$now = $this->clock->getTime();
		$claimLock = 'proofing-gallery/video-transcode/claim';
		$this->locks->acquireLock($claimLock, ILockingProvider::LOCK_EXCLUSIVE, 'Proofing Gallery video transcode claim');
		try {
			$claimed = $this->repository->claim((int)$row['id'], $etag, $now, $this->policies->videoSettings()['concurrency']);
		} finally {
			$this->locks->releaseLock($claimLock, ILockingProvider::LOCK_EXCLUSIVE);
		}
		if (!$claimed) {
			$current = $this->repository->find($ownerUid, $fileId);
			if ($this->mayRetry($current, $etag, $now)) $this->queue($ownerUid, $fileId, $etag, $now + 30);
			return;
		}
		$input = $output = $poster = null;
		$storedKeys = [];
		try {
			$source = $this->resolve($ownerUid, $fileId, $etag);
			if ((int)$source->getSize() > $this->policies->get('maxVideoInputBytes')) throw new \RuntimeException('input_too_large');
			[$input, $output, $poster] = $this->temporaryPaths();
			$this->copySource($source, $input);
			$this->validateDuration($input);
			$this->transcode($input, $output, $poster);
			$key = hash('sha256', $ownerUid . ':' . $fileId . ':' . $etag . ':' . self::PROFILE);
			$this->store($output, $key . '.mp4');
			$storedKeys[] = $key . '.mp4';
			$this->store($poster, $key . '.jpg');
			$storedKeys[] = $key . '.jpg';
			if (!$this->repository->complete((int)$row['id'], $etag, $key . '.mp4', $key . '.jpg', filesize($output) ?: 0, $this->clock->getTime())) {
				// The source changed while ffmpeg was running. The current queue row must win.
				foreach ($storedKeys as $storedKey) $this->deleteStored($storedKey);
			}
		} catch (\Throwable $exception) {
			$this->repository->fail((int)$row['id'], $etag, $this->errorCode($exception), $this->clock->getTime());
			foreach ($storedKeys as $storedKey) $this->deleteStored($storedKey);
			$current = $this->repository->find($ownerUid, $fileId);
			if ($this->mayRetry($current, $etag, $this->clock->getTime())) {
				$attempts = max(1, (int)($current['attempts'] ?? 1));
				$this->queue($ownerUid, $fileId, $etag, $this->clock->getTime() + min(300, 30 * (2 ** ($attempts - 1))));
			}
		} finally {
			foreach ([$input, $output, $poster] as $path) if (is_string($path) && is_file($path)) @unlink($path);
		}
	}

	/** @return array{available: bool, version: string|null} */
	public function availability(): array {
		$settings = $this->policies->videoSettings();
		$result = $this->commands->run([$settings['ffmpegPath'], '-version'], 5);
		$line = trim(strtok($result['stdout'], "\n") ?: '');
		return ['available' => $result['exitCode'] === 0, 'version' => $line === '' ? null : mb_substr($line, 0, 160)];
	}

	private function resolve(string $ownerUid, int $fileId, string $etag): File {
		foreach ($this->rootFolder->getUserFolder($ownerUid)->getById($fileId) as $node) {
			if ($node instanceof File && $node->isReadable() && $node->getEtag() === $etag && str_starts_with($node->getMimeType(), 'video/')) return $node;
		}
		throw new \RuntimeException('source_unavailable');
	}

	/** @return array{string, string, string} */
	private function temporaryPaths(): array {
		$base = tempnam(sys_get_temp_dir(), 'proofing-video-');
		if ($base === false) throw new \RuntimeException('temporary_storage');
		@unlink($base);
		return [$base . '.source', $base . '.mp4', $base . '.jpg'];
	}

	private function copySource(File $source, string $path): void {
		$input = $source->fopen('rb');
		$output = @fopen($path, 'wb');
		if (!is_resource($input) || !is_resource($output)) throw new \RuntimeException('source_unavailable');
		try {
			if (stream_copy_to_stream($input, $output) === false) throw new \RuntimeException('source_unavailable');
		} finally {
			fclose($input);
			fclose($output);
		}
	}

	private function validateDuration(string $input): void {
		$settings = $this->policies->videoSettings();
		$probe = preg_replace('/ffmpeg(?:\.exe)?$/i', 'ffprobe$1', $settings['ffmpegPath']) ?: 'ffprobe';
		$result = $this->commands->run([$probe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $input], 20);
		if ($result['exitCode'] !== 0 || !is_numeric(trim($result['stdout']))) throw new \RuntimeException('probe_failed');
		if ((float)trim($result['stdout']) > $this->policies->get('maxVideoDurationSeconds')) throw new \RuntimeException('duration_limit');
	}

	private function transcode(string $input, string $output, string $poster): void {
		$settings = $this->policies->videoSettings();
		$height = (string)$this->policies->get('videoMaxHeight');
		$timeout = $this->policies->get('videoTranscodeTimeoutSeconds');
		$scale = "scale=w=-2:h='min({$height},ih)':force_original_aspect_ratio=decrease";
		$result = $this->commands->run([$settings['ffmpegPath'], '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $input, '-map', '0:v:0', '-map', '0:a:0?', '-vf', $scale, '-c:v', 'libx264', '-preset', $settings['preset'], '-crf', '23', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-c:a', 'aac', '-b:a', '160k', $output], $timeout);
		if ($result['exitCode'] !== 0 || !is_file($output) || filesize($output) === 0) throw new \RuntimeException($result['timedOut'] ? 'timeout' : 'transcode_failed');
		$thumbnail = $this->commands->run([$settings['ffmpegPath'], '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-ss', '0.1', '-i', $input, '-frames:v', '1', '-vf', "scale=w=-2:h='min(1080,ih)'", '-q:v', '3', $poster], min(60, $timeout));
		if ($thumbnail['exitCode'] !== 0 || !is_file($poster)) throw new \RuntimeException('poster_failed');
	}

	private function store(string $path, string $key): void {
		$source = @fopen($path, 'rb');
		if (!is_resource($source)) throw new \RuntimeException('temporary_storage');
		try {
			$folder = $this->folder();
			$file = $folder->fileExists($key) ? $folder->getFile($key) : $folder->newFile($key);
			$file->putContent($source);
		} finally {
			if (is_resource($source)) fclose($source);
		}
	}

	private function deleteStored(mixed $key): void {
		if (!is_string($key) || $key === '') return;
		try {
			$folder = $this->folder();
			if ($folder->fileExists($key)) $folder->getFile($key)->delete();
		} catch (\Throwable) {
			// Lifecycle cleanup is the final safety net for an orphaned derivative.
		}
	}

	private function queue(string $ownerUid, int $fileId, string $etag, ?int $runAfter = null): void {
		$argument = ['ownerUid' => $ownerUid, 'fileId' => $fileId, 'etag' => $etag];
		if ($this->jobs->has(TranscodeVideoJob::class, $argument)) return;
		if ($runAfter === null) $this->jobs->add(TranscodeVideoJob::class, $argument);
		else $this->jobs->scheduleAfter(TranscodeVideoJob::class, $runAfter, $argument);
	}

	/** @param array<string, mixed>|null $row */
	private function mayRetry(?array $row, string $etag, int $now): bool {
		if ($row === null || (string)$row['source_etag'] !== $etag) return false;
		return match ((string)$row['status']) {
			'pending' => true,
			'processing' => (int)$row['updated_at'] <= $now - 3600,
			'failed' => (int)$row['attempts'] < 3,
			default => false,
		};
	}

	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder('video-derivatives');
		} catch (\OCP\Files\NotFoundException) {
			return $this->appData->newFolder('video-derivatives');
		}
	}

	private function errorCode(\Throwable $exception): string {
		$code = preg_replace('/[^a-z0-9_]+/i', '_', $exception->getMessage()) ?: 'unknown';
		return strtolower(trim($code, '_'));
	}
}
