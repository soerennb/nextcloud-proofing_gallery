<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\IConfig;
use OCP\IDBConnection;

final class HealthService {
	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
		private IConfig $config,
	) {
	}

	/** @return array<string, int|string|null> */
	public function status(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', $qb->func()->count('*', 'count'))
			->from('proofing_uploads')
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				['pending', 'awaiting_review'],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->groupBy('status');
		$counts = ['pending' => 0, 'awaiting_review' => 0];
		foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
			$counts[(string)$row['status']] = (int)$row['count'];
		}

		$previewBytes = 0;
		try {
			foreach ($this->appData->getFolder('watermarked-previews')->getDirectoryListing() as $file) {
				$previewBytes += $file->getSize();
			}
		} catch (\OCP\Files\NotFoundException) {
		}

		$lastRun = $this->config->getAppValue(Application::APP_ID, 'lastCleanupAt', '');
		$lastResult = $this->config->getAppValue(Application::APP_ID, 'lastCleanupResult', '');
		return [
			'pendingUploads' => $counts['pending'],
			'awaitingReview' => $counts['awaiting_review'],
			'previewCacheBytes' => $previewBytes,
			'lastCleanupAt' => $lastRun === '' ? null : (int)$lastRun,
			'lastCleanupResult' => $lastResult,
		];
	}
}
