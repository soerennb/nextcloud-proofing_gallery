<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\PrivacyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

final class ProcessPurgeRequestsJob extends TimedJob {
	public function __construct(ITimeFactory $time, private PrivacyService $privacy) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$this->privacy->processDue();
	}
}
