<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCA\ProofingGallery\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<GalleryIntegrationEvent> */
final class ActivityPublicationListener implements IEventListener {
	public function __construct(private GalleryMapper $galleries, private NotificationService $notifications) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GalleryIntegrationEvent || $event->type !== 'activity.staged' || $event->galleryId === null) return;
		try { $gallery = $this->galleries->find($event->galleryId); } catch (DoesNotExistException) { return; }
		$payload = $event->payload;
		if (!is_string($payload['type'] ?? null) || !is_int($payload['createdAt'] ?? null) || !is_array($payload['recipients'] ?? null) || !is_array($payload['nativeStateIds'] ?? null)) throw new \UnexpectedValueException('Malformed staged activity publication');
		$recipients = array_values(array_filter($payload['recipients'], 'is_string'));
		$nativeIds = array_values(array_filter($payload['nativeStateIds'], 'is_int'));
		if (count($recipients) !== count($payload['recipients']) || count($nativeIds) !== count($payload['nativeStateIds'])) throw new \UnexpectedValueException('Malformed staged activity recipients');
		$this->notifications->publishActivity($gallery, $payload['type'], $payload['createdAt'], $recipients, $nativeIds);
	}
}
