<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\RepairStep;

use OCA\ProofingGallery\RepairStep\NormalizeBackgroundJobs;
use OCA\ProofingGallery\Service\BackgroundMaintenanceHealthService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class NormalizeBackgroundJobsTest extends TestCase {
	public function testKeepsTheMostRecentlyRunJobAndRemovesLegacyDuplicates(): void {
		$older = $this->createMock(IJob::class);
		$older->method('getId')->willReturn(10);
		$older->method('getLastRun')->willReturn(100);
		$newer = $this->createMock(IJob::class);
		$newer->method('getId')->willReturn(11);
		$newer->method('getLastRun')->willReturn(200);
		$jobs = $this->createMock(IJobList::class);
		$first = BackgroundMaintenanceHealthService::PERIODIC_JOBS[0];
		$jobs->method('getJobsIterator')->willReturnCallback(
			static fn (string $class): iterable => $class === $first
				? [$older, $newer]
				: new \ArrayIterator([$newer]),
		);
		$jobs->expects(self::once())->method('removeById')->with(10);
		$jobs->expects(self::never())->method('add');

		(new NormalizeBackgroundJobs($jobs))->run($this->createMock(IOutput::class));
	}
}
