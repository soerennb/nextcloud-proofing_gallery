<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\GuestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

final class ContinuePurgeGuestsJob extends QueuedJob {
	private const BATCH_SIZE = 1000;

	public function __construct(
		ITimeFactory $time,
		private GuestService $guests,
		private IJobList $jobs,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		if ($this->guests->purgeExpired(self::BATCH_SIZE) === self::BATCH_SIZE) {
			$this->jobs->add(self::class, []);
		}
	}
}
