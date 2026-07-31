<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\IDBConnection;

final class HealthService {
	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
		private CleanupTelemetryService $cleanupTelemetry,
	) {
	}

	/**
	 * @return array{
	 *   pendingUploads: int,
	 *   awaitingReview: int,
	 *   previewCacheBytes: int,
	 *   cleanup: array<string, int|string|null>
	 * }
	 */
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

		return [
			'pendingUploads' => $counts['pending'],
			'awaitingReview' => $counts['awaiting_review'],
			'previewCacheBytes' => $previewBytes,
			'cleanup' => $this->cleanupTelemetry->status(),
		];
	}
}
