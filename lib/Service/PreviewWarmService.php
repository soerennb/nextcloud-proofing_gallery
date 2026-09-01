<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\Files\File;
use OCP\Files\IRootFolder;

final class PreviewWarmService {
	public function __construct(
		private FolderService $folders,
		private MediaSummaryService $summaries,
		private CollectionService $collections,
		private IRootFolder $rootFolder,
		private WatermarkPreviewService $watermarks,
	) {
	}

	public function warm(Gallery $gallery): void {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$cover = $this->cover($gallery);
		if ($cover !== null && str_starts_with($cover->getMimeType(), 'image/')) {
			$this->watermarks->render($cover, 900, 900, $settings->presentation, $gallery->getOwnerUid(), 'fit');
		}
		if ($settings->presentation->heroFileId !== null) {
			foreach ($this->rootFolder->getUserFolder($gallery->getOwnerUid())->getById($settings->presentation->heroFileId) as $node) {
				if ($node instanceof File && str_starts_with($node->getMimeType(), 'image/') && $node->isReadable()) {
					$this->watermarks->render($node, 1800, 1000, GallerySettings::defaults()->presentation, $gallery->getOwnerUid(), 'cover');
					break;
				}
			}
		}
	}

	private function cover(Gallery $gallery): ?File {
		if ($gallery->getSourceType() === 'collection') {
			$coverId = $this->collections->summary($gallery)['coverFileId'];
			return $coverId === null ? null : $this->collections->resolveMedia($gallery, $coverId);
		}
		$folder = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$coverId = $this->summaries->forFolder((int)$gallery->getId(), $gallery->getFolderId(), $folder)['coverFileId'];
		return $coverId === null ? null : $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $coverId);
	}
}
