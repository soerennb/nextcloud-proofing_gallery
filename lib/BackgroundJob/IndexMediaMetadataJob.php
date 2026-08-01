<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

final class IndexMediaMetadataJob extends QueuedJob {
	public function __construct(
		private GalleryMapper $galleries,
		private FolderService $folders,
		private MediaMetadataService $metadata,
		private LoggerInterface $logger,
	) {
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$galleryId = (int)($argument['galleryId'] ?? 0);
		$fileId = (int)($argument['fileId'] ?? 0);
		if ($galleryId < 1 || $fileId < 1) return;
		try {
			$gallery = $this->galleries->find($galleryId);
			$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
			$this->metadata->index($file);
		} catch (\Throwable $exception) {
			$this->logger->warning('Uploaded media metadata could not be indexed', [
				'galleryId' => $galleryId,
				'fileId' => $fileId,
				'exception' => $exception,
			]);
		}
	}
}
