<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\PrivacyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

final class ContinuePurgeRequestsJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private PrivacyService $privacy) {
		parent::__construct($time);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$this->privacy->processDue();
	}
}
