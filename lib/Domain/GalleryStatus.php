<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

enum GalleryStatus: string {
	case Draft = 'draft';
	case Published = 'published';
	case Archived = 'archived';
}
