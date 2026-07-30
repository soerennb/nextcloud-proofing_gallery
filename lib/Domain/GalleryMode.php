<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

enum GalleryMode: string {
	case Presentation = 'presentation';
	case Collaboration = 'collaboration';
}
