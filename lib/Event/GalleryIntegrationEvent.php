<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Event;

use OCP\EventDispatcher\Event;

final class GalleryIntegrationEvent extends Event {
	/** @param array<string, mixed> $payload */
	public function __construct(
		public readonly string $type,
		public readonly ?int $galleryId,
		public readonly array $payload,
	) {
		parent::__construct();
	}
}
