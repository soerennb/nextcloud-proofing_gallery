<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

enum PublicLinkCapability: string {
	case View = 'view';
	case Likes = 'likes';
	case Colors = 'colors';
	case Comments = 'comments';
	case Annotations = 'annotations';
	case Selections = 'selections';
	case Ratings = 'ratings';
	case Pick = 'pick';
	case Upload = 'upload';
	case Export = 'export';
	case Metadata = 'metadata';
}
