<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\IntegrationOutboxRepository;
use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

final class IntegrationEventService {
	public function __construct(
		private IntegrationOutboxRepository $outbox,
		private IEventDispatcher $events,
		private ITimeFactory $clock,
		private LoggerInterface $logger,
	) {
	}

	/** @param array<string, mixed> $payload */
	public function emit(string $type, ?int $galleryId, array $payload = []): void {
		if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $type) !== 1) {
			throw new \InvalidArgumentException('Invalid integration event type');
		}
		$safePayload = $this->sanitize($payload);
		$safePayload['eventId'] ??= bin2hex(random_bytes(16));
		$now = $this->clock->getTime();
		$id = $this->outbox->enqueue($galleryId, $type, $safePayload, $now);
		try {
			$this->events->dispatchTyped(new GalleryIntegrationEvent($type, $galleryId, $safePayload));
			$this->outbox->delete($id);
		} catch (\Throwable $exception) {
			$parts = explode('\\', $exception::class);
			$this->outbox->retry($id, 1, $now, 'dispatch_failed:' . end($parts));
			$this->logger->warning('Integration event delivery deferred', [
				'app' => 'proofing_gallery',
				'eventType' => $type,
				'eventId' => $safePayload['eventId'],
				'exception' => $exception,
			]);
		}
	}

	private function sanitize(mixed $value): mixed {
		if (is_string($value)) return mb_substr(trim(strip_tags($value)), 0, 2000);
		if (!is_array($value)) return $value;
		$result = [];
		foreach ($value as $key => $item) {
			if (in_array((string)$key, ['token', 'password', 'email', 'guestId', 'shareToken'], true)) continue;
			$result[$key] = $this->sanitize($item);
		}
		return $result;
	}
}
