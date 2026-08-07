<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

enum GalleryPurpose: string {
	case Showcase = 'showcase';
	case Delivery = 'delivery';
	case Selection = 'selection';
	case Proofing = 'proofing';
	case Uploads = 'uploads';
	case Custom = 'custom';

	/** @return array<string, mixed> */
	public function settings(): array {
		return match ($this) {
			self::Showcase => [...self::patch('presentation', 'none'), 'presentation' => ['openerStyle' => 'cinematic']],
			self::Delivery => [...self::patch('presentation', 'all'), 'presentation' => ['openerStyle' => 'cinematic']],
			self::Selection => [...self::patch('collaboration', 'none', selections: true), 'presentation' => ['openerStyle' => 'compact']],
			self::Proofing => [...self::patch('collaboration', 'none', likes: true, colors: true, comments: true, annotations: true, selections: true), 'presentation' => ['openerStyle' => 'compact']],
			self::Uploads => [...self::patch('presentation', 'none'), 'presentation' => ['openerStyle' => 'compact'], 'delivery' => [
				'downloadScope' => 'none',
				'guestUploads' => true,
			]],
			self::Custom => [],
		};
	}

	/** @return array<string, mixed> */
	private static function patch(
		string $mode,
		string $downloadScope,
		bool $likes = false,
		bool $colors = false,
		bool $comments = false,
		bool $annotations = false,
		bool $selections = false,
	): array {
		return [
			'mode' => $mode,
			'review' => compact('likes', 'colors', 'comments', 'annotations', 'selections'),
			'delivery' => ['downloadScope' => $downloadScope, 'guestUploads' => false],
		];
	}
}
