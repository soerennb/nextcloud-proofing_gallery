<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\LifecycleService;
use OCA\ProofingGallery\Service\CleanupTelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJobList;

final class CleanupGalleryDataJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private LifecycleService $lifecycle,
		private CleanupTelemetryService $telemetry,
		private IJobList $jobs,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$this->telemetry->recordAttempt();
		try {
			$result = $this->lifecycle->cleanup();
			$this->telemetry->recordSuccess($result);
			if (($result['remaining'] ?? 0) === 1) {
				$this->jobs->add(ContinueCleanupGalleryDataJob::class, []);
			}
		} catch (\Throwable $exception) {
			$this->telemetry->recordFailure($exception);
			throw $exception;
		}
	}
}
