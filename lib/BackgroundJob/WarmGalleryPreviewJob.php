<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\PreviewWarmService;
use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

final class WarmGalleryPreviewJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private GalleryMapper $galleries,
		private PreviewWarmService $previews,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$galleryId = (int)($argument['galleryId'] ?? 0);
		if ($galleryId < 1) return;
		try {
			$this->previews->warm($this->galleries->find($galleryId));
		} catch (\Throwable $exception) {
			$this->logger->warning('Gallery previews could not be warmed', [
				'galleryId' => $galleryId,
				'exception' => $exception,
			]);
		}
	}
}
