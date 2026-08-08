<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\CleanupGalleryDataJob;
use OCA\ProofingGallery\BackgroundJob\DispatchIntegrationOutboxJob;
use OCA\ProofingGallery\BackgroundJob\ProcessPurgeRequestsJob;
use OCA\ProofingGallery\BackgroundJob\PurgeGuestsJob;
use OCA\ProofingGallery\BackgroundJob\RevalidateCustomDomainsJob;
use OCA\ProofingGallery\BackgroundJob\SendNotificationDigestsJob;
use OCP\BackgroundJob\IJobList;

final class BackgroundMaintenanceHealthService {
	/** @var list<class-string> */
	public const PERIODIC_JOBS = [
		PurgeGuestsJob::class,
		CleanupGalleryDataJob::class,
		SendNotificationDigestsJob::class,
		RevalidateCustomDomainsJob::class,
		DispatchIntegrationOutboxJob::class,
		ProcessPurgeRequestsJob::class,
	];

	public function __construct(
		private IJobList $jobs,
		private ProjectionBackfillState $backfills,
	) {
	}

	/** @return array{periodicJobs:array{registered:int,expected:int,missing:list<string>,duplicates:list<string>},backfills:array<string,array<string,mixed>>} */
	public function status(): array {
		$counts = [];
		foreach ($this->jobs->countByClass() as $row) $counts[ltrim((string)$row['class'], '\\')] = (int)$row['count'];
		$missing = [];
		$duplicates = [];
		foreach (self::PERIODIC_JOBS as $job) {
			if (($counts[ltrim($job, '\\')] ?? 0) < 1) $missing[] = $job;
			if (($counts[ltrim($job, '\\')] ?? 0) > 1) $duplicates[] = $job;
		}
		return [
			'periodicJobs' => ['registered' => count(self::PERIODIC_JOBS) - count($missing), 'expected' => count(self::PERIODIC_JOBS), 'missing' => $missing, 'duplicates' => $duplicates],
			'backfills' => [
				'lifecycle' => $this->backfills->health(ProjectionBackfillState::LIFECYCLE),
				'galleryList' => $this->backfills->health(ProjectionBackfillState::GALLERY_LIST),
			],
		];
	}
}
