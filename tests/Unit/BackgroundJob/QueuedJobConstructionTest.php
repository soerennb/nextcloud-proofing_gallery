<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\BackgroundJob;

use OCA\ProofingGallery\BackgroundJob\IndexMediaMetadataJob;
use OCA\ProofingGallery\BackgroundJob\IndexSemanticGalleryJob;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\BackgroundJob\TranscodeVideoJob;
use OCA\ProofingGallery\BackgroundJob\WarmGalleryPreviewJob;
use OCA\ProofingGallery\BackgroundJob\BackfillLifecycleScheduleJob;
use OCA\ProofingGallery\BackgroundJob\ContinueCleanupGalleryDataJob;
use OCA\ProofingGallery\BackgroundJob\ContinuePurgeGuestsJob;
use OCA\ProofingGallery\BackgroundJob\BackfillGalleryListProjectionJob;
use OCA\ProofingGallery\BackgroundJob\ImportGalleryContextContentJob;
use OCA\ProofingGallery\BackgroundJob\ContinuePurgeRequestsJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class QueuedJobConstructionTest extends TestCase {
	/** @return list<array{class-string}> */
	public static function jobs(): array {
		return [
			[IndexMediaMetadataJob::class],
			[IndexSemanticGalleryJob::class],
			[RebuildMediaIndexJob::class],
			[TranscodeVideoJob::class],
			[WarmGalleryPreviewJob::class],
			[BackfillLifecycleScheduleJob::class],
			[ContinueCleanupGalleryDataJob::class],
			[ContinuePurgeGuestsJob::class],
			[BackfillGalleryListProjectionJob::class],
			[ImportGalleryContextContentJob::class],
			[ContinuePurgeRequestsJob::class],
		];
	}

	/** @param class-string $jobClass */
	#[DataProvider('jobs')]
	public function testQueuedJobInitializesNextcloudTimeFactory(string $jobClass): void {
		$time = $this->createMock(ITimeFactory::class);
		$constructor = (new ReflectionClass($jobClass))->getConstructor();
		self::assertNotNull($constructor);
		$arguments = array_map(function ($parameter) use ($time): object {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$class = $type->getName();
			if ($class === ITimeFactory::class) return $time;
			$reflection = new ReflectionClass($class);
			return $reflection->isInterface()
				? $this->createMock($class)
				: $reflection->newInstanceWithoutConstructor();
		}, $constructor->getParameters());

		$job = (new ReflectionClass($jobClass))->newInstanceArgs($arguments);
		$property = (new ReflectionClass(Job::class))->getProperty('time');
		self::assertSame($time, $property->getValue($job));
	}
}
