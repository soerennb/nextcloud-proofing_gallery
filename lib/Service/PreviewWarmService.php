<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\Files\File;
use OCP\Files\IRootFolder;

final class PreviewWarmService {
	public function __construct(
		private IRootFolder $rootFolder,
		private WatermarkPreviewService $watermarks,
		private GalleryArtworkService $artwork,
	) {
	}

	public function warm(Gallery $gallery): void {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$cover = $this->artwork->cover($gallery);
		if ($cover !== null && str_starts_with($cover->getMimeType(), 'image/')) {
			$this->watermarks->render($cover, 900, 900, $settings->presentation, $gallery->getOwnerUid(), 'fit');
		}
		$heroId = GalleryArtworkService::heroFileId($settings->presentation);
		if ($heroId !== null) {
			foreach ($this->rootFolder->getUserFolder($gallery->getOwnerUid())->getById($heroId) as $node) {
				if ($node instanceof File && str_starts_with($node->getMimeType(), 'image/') && $node->isReadable()) {
					$this->watermarks->render($node, 1800, 1000, GallerySettings::defaults()->presentation, $gallery->getOwnerUid(), 'cover');
					break;
				}
			}
		}
	}

}
