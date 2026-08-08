<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\RepairStep;

use OCA\ProofingGallery\Service\BackgroundMaintenanceHealthService;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Removes duplicates left by legacy request-time registration without resetting the retained job. */
final class NormalizeBackgroundJobs implements IRepairStep {
	public function __construct(private IJobList $jobs) {
	}

	public function getName(): string {
		return 'Normalize Proofing Gallery periodic background jobs';
	}

	public function run(IOutput $output): void {
		foreach (BackgroundMaintenanceHealthService::PERIODIC_JOBS as $class) {
			$registered = iterator_to_array($this->jobs->getJobsIterator($class, null, 0), false);
			if ($registered === []) {
				$this->jobs->add($class);
				continue;
			}
			usort($registered, static fn ($left, $right): int => $right->getLastRun() <=> $left->getLastRun());
			foreach (array_slice($registered, 1) as $duplicate) {
				// NC34's implementation temporarily types this identifier as string
				// while the public NC31 OCP contract uses int. Reflection safely
				// adapts the scalar across the supported server range.
				(new \ReflectionMethod($this->jobs, 'removeById'))->invoke($this->jobs, $duplicate->getId());
			}
		}
	}
}
