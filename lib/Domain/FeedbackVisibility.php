<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

enum FeedbackVisibility: string {
	case Collaborative = 'collaborative';
	case Private = 'private';
}
