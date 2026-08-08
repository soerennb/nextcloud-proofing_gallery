<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;

final class GalleryListProjectionService {
	public function project(Gallery $gallery): void {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$gallery->setMode($settings->mode->value);
		$gallery->setTitleSort(mb_strtolower(trim($gallery->getTitle())));
	}
}
