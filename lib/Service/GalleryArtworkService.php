<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Dto\Settings\PresentationSettings;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\Files\File;

final class GalleryArtworkService {
	public function __construct(
		private FolderService $folders,
		private CollectionService $collections,
		private MediaSummaryService $summaries,
		private PublicMediaResolver $publicMedia,
		private \OCP\IPreview $previews,
	) {
	}

	public static function heroFileId(PresentationSettings $presentation): ?int {
		return match ($presentation->heroSource) {
			'cover' => $presentation->coverFileId,
			'custom' => $presentation->heroFileId,
			default => null,
		};
	}

	public function publicHeroFileId(PublicShareContext $context): ?int {
		$presentation = $context->settings->presentation;
		$id = self::heroFileId($presentation);
		return $id !== null && $presentation->heroSource === 'cover' && !$this->publicMedia->allows($context, $id) ? null : $id;
	}

	public function cover(Gallery $gallery): ?File {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		if ($settings->presentation->coverFileId !== null) {
			$file = $this->resolve($gallery, $settings->presentation->coverFileId);
			if ($file !== null && $this->previews->isAvailable($file)) return $file;
		}
		if ($gallery->getSourceType() === 'collection') {
			$video = null;
			foreach ($this->collections->availableItems($gallery) as $item) {
				$file = $this->resolve($gallery, (int)$item['id']);
				if ($file === null || !$this->previews->isAvailable($file)) continue;
				if (str_starts_with($file->getMimeType(), 'image/')) return $file;
				$video ??= $file;
			}
			return $video;
		}
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$summary = $this->summaries->forFolder((int)$gallery->getId(), $gallery->getFolderId(), $root);
		$id = $summary['coverFileId'];
		$file = $id === null ? null : $this->resolve($gallery, $id);
		if ($id !== null && ($file === null || !$this->previews->isAvailable($file))) {
			$this->summaries->invalidate((int)$gallery->getId());
			$id = $this->summaries->forFolder((int)$gallery->getId(), $gallery->getFolderId(), $root)['coverFileId'];
			$file = $id === null ? null : $this->resolve($gallery, $id);
		}
		return $file;
	}

	private function resolve(Gallery $gallery, int $id): ?File {
		try {
			return $gallery->getSourceType() === 'collection'
				? $this->collections->resolveMedia($gallery, $id)
				: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $id);
		} catch (FolderAccessException|\OCP\Files\NotFoundException|\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}
}
