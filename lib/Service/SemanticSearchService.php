<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\BackgroundJob\IndexSemanticGalleryJob;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\SemanticIndexRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Lock\ILockingProvider;

final class SemanticSearchService {
	public function __construct(
		private SemanticIndexRepository $repository,
		private SemanticEmbeddingProvider $provider,
		private SemanticVectorizer $vectors,
		private FolderService $folders,
		private CollectionService $collections,
		private PolicyService $policies,
		private ITimeFactory $clock,
		private IJobList $jobs,
		private IConfig $config,
		private ILockingProvider $locks,
	) {
	}

	/** @return array{state: string, provider: string, model: string} */
	public function enqueue(Gallery $gallery): array {
		$settings = $this->policies->semanticSettings();
		if ($settings['provider'] === 'disabled') throw new \InvalidArgumentException('Semantic search is disabled by the administrator');
		$generation = bin2hex(random_bytes(16));
		$galleryId = (int)$gallery->getId();
		$lock = $this->lockPath($galleryId);
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Proofing Gallery media search index');
		try {
			$this->config->setAppValue(Application::APP_ID, $this->pendingKey($galleryId), $generation);
			$this->config->deleteAppValue(Application::APP_ID, $this->errorKey($galleryId));
			$this->queue($galleryId, $generation, $settings['provider'], $settings['model'], 0, 0);
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
		return ['state' => 'queued', 'provider' => $settings['provider'], 'model' => $settings['model']];
	}

	/** @return array{indexed: int, failed: int, total: int, complete: bool} */
	public function indexBatch(Gallery $gallery, string $generation, string $provider, string $model, int $offset, int $attempt): array {
		$galleryId = (int)$gallery->getId();
		if (!$this->isPending($galleryId, $generation)) return ['indexed' => 0, 'failed' => 0, 'total' => 0, 'complete' => true];
		$settings = $this->policies->semanticSettings();
		if ($settings['provider'] !== $provider || $settings['model'] !== $model) {
			$this->markFailed($galleryId, $generation, 'provider_configuration_changed');
			return ['indexed' => 0, 'failed' => 1, 'total' => 0, 'complete' => false];
		}
		try {
			$files = $this->media($gallery);
		} catch (\Throwable $exception) {
			$this->retry($galleryId, $generation, $provider, $model, $offset, $attempt, $exception);
			return ['indexed' => 0, 'failed' => 1, 'total' => 0, 'complete' => false];
		}
		$total = count($files);
		$batch = array_slice($files, $offset, $this->policies->get('semanticBatchSize'));
		$indexed = 0;
		foreach ($batch as $file) {
			try {
				$embedding = $this->provider->media($file);
				$this->repository->upsert($galleryId, (int)$file->getId(), $file->getEtag(), $embedding['provider'], $embedding['model'], $generation, $embedding['vector'], $embedding['concepts'], $this->clock->getTime());
				$indexed++;
			} catch (\Throwable $exception) {
				$this->retry($galleryId, $generation, $provider, $model, $offset, $attempt, $exception);
				return ['indexed' => $indexed, 'failed' => 1, 'total' => $total, 'complete' => false];
			}
		}
		$next = $offset + count($batch);
		$complete = $next >= $total;
		if ($complete) $this->activate($galleryId, $generation);
		else $this->queue($galleryId, $generation, $provider, $model, $next, 0);
		return ['indexed' => $indexed, 'failed' => 0, 'total' => $total, 'complete' => $complete];
	}

	/** @return list<array{fileId: int, score: float, concepts: list<string>}> */
	public function search(Gallery $gallery, string $query, int $limit = 40): array {
		$query = trim(mb_substr($query, 0, 200));
		if (mb_strlen($query) < 2) throw new \InvalidArgumentException('Enter at least two characters');
		$settings = $this->policies->semanticSettings();
		$generation = $this->config->getAppValue(Application::APP_ID, $this->activeKey((int)$gallery->getId()), 'legacy');
		$needle = $this->provider->query($query);
		$results = [];
		foreach ($this->repository->gallery((int)$gallery->getId(), $settings['provider'], $settings['model'], $generation, $this->policies->get('maxSemanticMedia')) as $row) {
			try {
				$file = $gallery->getSourceType() === 'collection'
					? $this->collections->resolveMedia($gallery, (int)$row['file_id'])
					: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), (int)$row['file_id']);
				if (!hash_equals((string)$row['source_etag'], $file->getEtag()) || !$this->inScope($file)) continue;
				$vector = json_decode((string)$row['vector'], true, flags: JSON_THROW_ON_ERROR);
				$concepts = json_decode((string)$row['concepts'], true, flags: JSON_THROW_ON_ERROR);
				if (!is_array($vector) || !is_array($concepts)) continue;
				$results[] = ['fileId' => (int)$row['file_id'], 'score' => $this->vectors->similarity(array_map('floatval', $needle), array_map('floatval', $vector)), 'concepts' => array_values(array_map('strval', $concepts))];
			} catch (\Throwable) {
			}
		}
		usort($results, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
		return array_slice($results, 0, max(1, min(100, $limit)));
	}

	public function delete(Gallery $gallery): int {
		$galleryId = (int)$gallery->getId();
		foreach ([$this->activeKey($galleryId), $this->pendingKey($galleryId), $this->errorKey($galleryId)] as $key) {
			$this->config->deleteAppValue(Application::APP_ID, $key);
		}
		return $this->repository->deleteGallery($galleryId);
	}

	/** @return array{enabled: bool, provider: string, model: string, scope: string, state: string, error: ?string} */
	public function status(Gallery $gallery): array {
		$settings = $this->policies->semanticSettings();
		$galleryId = (int)$gallery->getId();
		$active = $this->config->getAppValue(Application::APP_ID, $this->activeKey($galleryId), '');
		$pending = $this->config->getAppValue(Application::APP_ID, $this->pendingKey($galleryId), '');
		$error = $this->config->getAppValue(Application::APP_ID, $this->errorKey($galleryId), '');
		$enabled = $settings['provider'] !== 'disabled';
		$state = match (true) {
			!$enabled => 'disabled',
			$pending !== '' && $error !== '' => 'failed',
			$pending !== '' => 'indexing',
			$active !== '' => 'ready',
			default => 'unindexed',
		};
		return ['enabled' => $enabled, 'provider' => $settings['provider'], 'model' => $settings['model'], 'scope' => $settings['scope'], 'state' => $state, 'error' => $error === '' ? null : $error];
	}

	private function activate(int $galleryId, string $generation): void {
		$lock = $this->lockPath($galleryId);
		$this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Proofing Gallery media search index');
		try {
			if (!$this->isPending($galleryId, $generation)) return;
			$this->config->setAppValue(Application::APP_ID, $this->activeKey($galleryId), $generation);
			$this->config->deleteAppValue(Application::APP_ID, $this->pendingKey($galleryId));
			$this->config->deleteAppValue(Application::APP_ID, $this->errorKey($galleryId));
			$this->repository->deleteOtherGenerations($galleryId, $generation);
		} finally {
			$this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function retry(int $galleryId, string $generation, string $provider, string $model, int $offset, int $attempt, \Throwable $exception): void {
		$nextAttempt = $attempt + 1;
		if ($nextAttempt >= 5) {
			$this->markFailed($galleryId, $generation, $this->errorCode($exception));
			return;
		}
		$this->queue($galleryId, $generation, $provider, $model, $offset, $nextAttempt, $this->clock->getTime() + min(900, 30 * (2 ** $attempt)));
	}

	private function markFailed(int $galleryId, string $generation, string $error): void {
		if (!$this->isPending($galleryId, $generation)) return;
		$this->config->setAppValue(Application::APP_ID, $this->errorKey($galleryId), mb_substr($error, 0, 64));
	}

	private function queue(int $galleryId, string $generation, string $provider, string $model, int $offset, int $attempt, ?int $runAfter = null): void {
		$argument = compact('galleryId', 'generation', 'provider', 'model', 'offset', 'attempt');
		if ($this->jobs->has(IndexSemanticGalleryJob::class, $argument)) return;
		if ($runAfter === null) $this->jobs->add(IndexSemanticGalleryJob::class, $argument);
		else $this->jobs->scheduleAfter(IndexSemanticGalleryJob::class, $runAfter, $argument);
	}

	private function isPending(int $galleryId, string $generation): bool {
		return $generation !== '' && hash_equals($generation, $this->config->getAppValue(Application::APP_ID, $this->pendingKey($galleryId), ''));
	}

	private function activeKey(int $galleryId): string { return 'semantic.active.' . $galleryId; }
	private function pendingKey(int $galleryId): string { return 'semantic.pending.' . $galleryId; }
	private function errorKey(int $galleryId): string { return 'semantic.error.' . $galleryId; }
	private function lockPath(int $galleryId): string { return 'proofing-gallery/semantic-index/' . $galleryId; }

	private function errorCode(\Throwable $exception): string {
		return strtolower(trim(preg_replace('/[^a-z0-9_]+/i', '_', $exception->getMessage()) ?: 'index_failed', '_'));
	}

	/** @return list<File> */
	private function media(Gallery $gallery): array {
		$limit = $this->policies->get('maxSemanticMedia');
		if ($gallery->getSourceType() === 'collection') {
			$files = [];
			foreach ($this->collections->availableItems($gallery) as $item) {
				try {
					$files[] = $this->collections->resolveMedia($gallery, (int)$item['id']);
				} catch (\Throwable) {
				}
				if (count($files) >= $limit) break;
			}
			return array_values(array_filter($files, fn (File $file): bool => $this->inScope($file)));
		}
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$storage = $root->getStorage()->getId();
		$files = [];
		$pending = [$root];
		while ($pending !== [] && count($files) < $limit) {
			$folder = array_pop($pending);
			foreach ($folder->getDirectoryListing() as $node) {
				if (!$node->isReadable() || $node->getStorage()->getId() !== $storage || str_starts_with($node->getName(), '.')) continue;
				if ($node instanceof Folder) $pending[] = $node;
				elseif ($node instanceof File && $this->inScope($node)) $files[] = $node;
				if (count($files) >= $limit) break;
			}
		}
		usort($files, static fn (File $left, File $right): int => $left->getId() <=> $right->getId());
		return $files;
	}

	private function inScope(File $file): bool {
		$scope = $this->policies->semanticSettings()['scope'];
		return str_starts_with($file->getMimeType(), 'image/') || ($scope === 'images_and_video' && str_starts_with($file->getMimeType(), 'video/'));
	}
}
