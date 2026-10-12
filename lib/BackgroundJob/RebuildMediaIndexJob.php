<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\MediaIndexService;
use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

final class RebuildMediaIndexJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private GalleryMapper $galleries,
		private MediaIndexService $index,
		private LoggerInterface $logger,
		private \OCP\BackgroundJob\IJobList $jobs,
	) {
		parent::__construct($time);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		if (isset($argument['galleryIds'])) {
			foreach ($argument['galleryIds'] as $id) $this->jobs->add(self::class, ['galleryId' => (int)$id]);
			return;
		}
		$galleryId = (int)($argument['galleryId'] ?? 0);
		if ($galleryId < 1) return;
		try {
			$gallery = $this->galleries->find($galleryId);
			if ((bool)($argument['continuation'] ?? false)) $this->index->continueRebuild($gallery);
			else $this->index->rebuild($gallery);
		} catch (\OCP\AppFramework\Db\DoesNotExistException|\OCA\ProofingGallery\Exception\FolderAccessException) {
			// A removed or unreadable source needs a new file event, not retries.
		} catch (\Throwable $exception) {
			$retry = (int)($argument['retry'] ?? 0);
			if ($retry < 3) $this->jobs->add(self::class, ['galleryId' => $galleryId, 'continuation' => true, 'retry' => $retry + 1]);
			// File events race with gallery deletion, unmounting and permission
			// changes. A later event or explicit rebuild can repair the cache.
			$this->logger->warning('Gallery media index could not be rebuilt', [
				'galleryId' => $galleryId,
				'exception' => $exception,
			]);
		}
	}
}
