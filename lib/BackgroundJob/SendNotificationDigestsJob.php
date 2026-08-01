<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

final class SendNotificationDigestsJob extends TimedJob {
	public function __construct(ITimeFactory $time, private NotificationService $notifications) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$this->notifications->dispatch();
	}
}
