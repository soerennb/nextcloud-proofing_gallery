<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\EventWaveService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Queued jobs are removed before execution; recover after process termination. */
final class RecoverEventWavesJob extends TimedJob {
	public function __construct(ITimeFactory $clock, private EventWaveService $waves) {
		parent::__construct($clock);
		$this->setInterval(60);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$this->waves->recoverJobs();
	}
}
