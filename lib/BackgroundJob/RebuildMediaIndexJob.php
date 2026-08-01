<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\MediaIndexService;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

final class RebuildMediaIndexJob extends QueuedJob {
	public function __construct(
		private GalleryMapper $galleries,
		private MediaIndexService $index,
		private LoggerInterface $logger,
	) {
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$galleryId = (int)($argument['galleryId'] ?? 0);
		if ($galleryId < 1) return;
		try {
			$this->index->rebuild($this->galleries->find($galleryId));
		} catch (\Throwable $exception) {
			// File events race with gallery deletion, unmounting and permission
			// changes. A later event or explicit rebuild can repair the cache.
			$this->logger->warning('Gallery media index could not be rebuilt', [
				'galleryId' => $galleryId,
				'exception' => $exception,
			]);
		}
	}
}
