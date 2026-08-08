<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\CleanupTelemetryService;
use OCA\ProofingGallery\Service\LifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/** Continues a cleanup backlog while keeping every invocation bounded. */
final class ContinueCleanupGalleryDataJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private LifecycleService $lifecycle,
		private CleanupTelemetryService $telemetry,
		private IJobList $jobs,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$this->telemetry->recordAttempt();
		try {
			$result = $this->lifecycle->cleanup();
			$this->telemetry->recordSuccess($result);
			if (($result['remaining'] ?? 0) === 1) {
				$this->jobs->add(self::class, []);
			}
		} catch (\Throwable $exception) {
			$this->telemetry->recordFailure($exception);
			throw $exception;
		}
	}
}
