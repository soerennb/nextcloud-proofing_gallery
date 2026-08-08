<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\RepairStep;

use OCA\ProofingGallery\BackgroundJob\BackfillGalleryListProjectionJob;
use OCA\ProofingGallery\BackgroundJob\BackfillLifecycleScheduleJob;
use OCA\ProofingGallery\RepairStep\ScheduleProjectionBackfills;
use OCA\ProofingGallery\Service\ProjectionBackfillState;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class ScheduleProjectionBackfillsTest extends TestCase {
	public function testSchedulesIncompleteBackfillsFromTheirPersistedCursors(): void {
		$jobs = $this->createMock(IJobList::class);
		$values = [
			'lifecycleProjectionV1Cursor' => '7',
			'galleryListProjectionV1Cursor' => '11',
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $values[$key] ?? $default,
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$values): void { $values[$key] = $value; },
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1234);
		$state = new ProjectionBackfillState($config, $time);
		$jobs->method('has')->willReturn(false);
		$added = [];
		$jobs->expects(self::exactly(2))->method('add')->willReturnCallback(
			static function (string $job, array $argument) use (&$added): void { $added[] = [$job, $argument]; },
		);

		(new ScheduleProjectionBackfills($jobs, $state))->run($this->createMock(IOutput::class));

		self::assertSame([
			[BackfillLifecycleScheduleJob::class, ['afterId' => 7]],
			[BackfillGalleryListProjectionJob::class, ['afterId' => 11]],
		], $added);
	}
}
