<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Event;

use OCP\EventDispatcher\Event;

final class MediaMetadataIndexedEvent extends Event {
	public function __construct(public readonly int $fileId, public readonly string $etag, public readonly ?int $capturedAt, public readonly string $state = 'ready') {
		parent::__construct();
	}
}
