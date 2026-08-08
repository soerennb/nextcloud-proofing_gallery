<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\IntegrationOutboxRepository;
use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

final class DispatchIntegrationOutboxJob extends TimedJob {
	public function __construct(
		private ITimeFactory $clock,
		private IntegrationOutboxRepository $outbox,
		private IEventDispatcher $events,
		private LoggerInterface $logger,
	) {
		parent::__construct($clock);
		$this->setInterval(60);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$now = $this->clock->getTime();
		foreach ($this->outbox->ready($now) as $row) {
			$id = (int)$row['id'];
			$attempts = (int)$row['attempts'] + 1;
			try {
				$payload = json_decode((string)$row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
				$this->events->dispatchTyped(new GalleryIntegrationEvent(
					(string)$row['event_type'],
					$row['gallery_id'] === null ? null : (int)$row['gallery_id'],
					is_array($payload) ? $payload : [],
				));
				$this->outbox->delete($id);
			} catch (\Throwable $exception) {
				$parts = explode('\\', $exception::class);
				$this->outbox->retry($id, $attempts, $now, 'dispatch_failed:' . end($parts));
				$this->logger->warning('Integration event retry failed', [
					'app' => 'proofing_gallery',
					'eventType' => (string)$row['event_type'],
					'attempt' => $attempts,
					'exception' => $exception,
				]);
			}
		}
	}
}
