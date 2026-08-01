<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCP\Files\Folder;
use OCP\Share\IShare;

final class PublicShareContext {
	public function __construct(
		public readonly IShare $share,
		public readonly Gallery $gallery,
		public readonly GallerySettings $settings,
		public readonly PublicLink $link,
		public readonly PublicLinkPolicy $policy,
		public readonly Folder $root,
	) {
	}
}
