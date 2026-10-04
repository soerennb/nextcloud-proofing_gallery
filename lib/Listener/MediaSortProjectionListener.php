<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\Db\MediaSortRepository;
use OCA\ProofingGallery\Event\MediaMetadataIndexedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @implements IEventListener<MediaMetadataIndexedEvent> */
final class MediaSortProjectionListener implements IEventListener {
	public function __construct(private MediaSortRepository $sorts) {
	}

	public function handle(Event $event): void {
		if ($event instanceof MediaMetadataIndexedEvent) $this->sorts->updateCapture($event->fileId, $event->etag, $event->capturedAt, $event->state);
	}
}
