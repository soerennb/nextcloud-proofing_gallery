<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\GuestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJobList;

final class PurgeGuestsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private GuestService $guests,
		private IJobList $jobs,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		if ($this->guests->purgeExpired(1000) === 1000) {
			$this->jobs->add(ContinuePurgeGuestsJob::class, []);
		}
	}
}
