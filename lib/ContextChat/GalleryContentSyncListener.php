<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\ContextChat;

use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<GalleryIntegrationEvent> */
final class GalleryContentSyncListener implements IEventListener {
	public function __construct(private GalleryContentSyncService $sync) {
	}

	public function handle(Event $event): void {
		if ($event instanceof GalleryIntegrationEvent && $event->galleryId !== null) $this->sync->sync($event->galleryId);
	}
}
