<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\LifecycleService;
use OCA\ProofingGallery\Service\CleanupTelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

final class CleanupGalleryDataJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private LifecycleService $lifecycle,
		private CleanupTelemetryService $telemetry,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->telemetry->recordAttempt();
		try {
			$this->telemetry->recordSuccess($this->lifecycle->cleanup());
		} catch (\Throwable $exception) {
			$this->telemetry->recordFailure($exception);
			throw $exception;
		}
	}
}
