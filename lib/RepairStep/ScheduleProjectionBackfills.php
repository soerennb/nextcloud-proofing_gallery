<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\RepairStep;

use OCA\ProofingGallery\BackgroundJob\BackfillGalleryListProjectionJob;
use OCA\ProofingGallery\BackgroundJob\BackfillLifecycleScheduleJob;
use OCA\ProofingGallery\Service\ProjectionBackfillState;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Starts schema-dependent projection work only after migrations have completed. */
final class ScheduleProjectionBackfills implements IRepairStep {
	public function __construct(
		private IJobList $jobs,
		private ProjectionBackfillState $state,
	) {
	}

	public function getName(): string {
		return 'Schedule recoverable Proofing Gallery projection backfills';
	}

	public function run(IOutput $output): void {
		$this->schedule(BackfillLifecycleScheduleJob::class, ProjectionBackfillState::LIFECYCLE);
		$this->schedule(BackfillGalleryListProjectionJob::class, ProjectionBackfillState::GALLERY_LIST);
	}

	/** @param class-string $job */
	private function schedule(string $job, string $projection): void {
		if ($this->state->isComplete($projection)) return;

		$argument = ['afterId' => $this->state->cursor($projection)];
		$this->state->markPending($projection);
		if (!$this->jobs->has($job, $argument)) $this->jobs->add($job, $argument);
	}
}
