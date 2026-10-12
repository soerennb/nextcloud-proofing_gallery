<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;

final class GalleryInputValidator {
	public function __construct(
		private FolderService $folders,
		private CollectionService $collections,
		private DesignAssetService $designAssets,
	) {
	}

	public function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new InvalidArgumentException('Title must contain 1 to 255 characters');
		}
		return $title;
	}

	public function assertPresentationAssets(Gallery $gallery, GallerySettings $settings, ?GallerySettings $previous = null): void {
		$ids = [$settings->presentation->coverFileId, GalleryArtworkService::heroFileId($settings->presentation), $settings->presentation->logoFileId];
		$previousIds = $previous === null ? [] : [$previous->presentation->coverFileId, GalleryArtworkService::heroFileId($previous->presentation), $previous->presentation->logoFileId];
		foreach ($ids as $index => $fileId) {
			if ($fileId === null || ($previous !== null && $previousIds[$index] === $fileId)) continue;
			if ($gallery->getSourceType() === 'collection' && $gallery->getId() === null) {
				throw new InvalidArgumentException('An empty collection cannot reference gallery artwork');
			}
			try {
				$file = $gallery->getSourceType() === 'collection'
					? $this->collections->resolveMedia($gallery, $fileId)
					: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
			} catch (\OCA\ProofingGallery\Exception\FolderAccessException|\OCP\Files\NotFoundException|\OCP\AppFramework\Db\DoesNotExistException $exception) {
				throw new InvalidArgumentException('Gallery artwork must be an image inside the gallery source', previous: $exception);
			}
			if (!str_starts_with($file->getMimeType(), 'image/')) {
				throw new InvalidArgumentException('Gallery artwork must be an image inside the gallery source');
			}
		}
		foreach ([
			[$settings->presentation->logoAssetId, 'logo'],
			[$settings->presentation->watermarkImageAssetId, 'watermark'],
		] as [$assetId, $kind]) {
			if ($assetId === null) continue;
			try {
				$this->designAssets->owned($gallery->getOwnerUid(), $assetId, $kind);
			} catch (\OCP\Files\NotFoundException|\OCP\AppFramework\Db\DoesNotExistException $exception) {
				throw new InvalidArgumentException('Design assets must belong to the gallery owner and match their intended use', previous: $exception);
			}
		}
	}
}
