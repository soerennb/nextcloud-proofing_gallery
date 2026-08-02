<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Db\VideoDerivativeRepository;
use OCA\ProofingGallery\Db\SemanticIndexRepository;
use OCA\ProofingGallery\Db\LivePushRepository;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\IDBConnection;

final class HealthService {
	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
		private CleanupTelemetryService $cleanupTelemetry,
		private IAppManager $apps,
		private VideoDerivativeRepository $videoDerivatives,
		private VideoTranscodeService $videoTranscodes,
		private SemanticIndexRepository $semanticIndex,
		private LivePushRepository $livePush,
	) {
	}

	/**
	 * @return array{
	 *   pendingUploads: int,
	 *   awaitingReview: int,
	 *   previewCacheBytes: int,
	 *   notifications: array{available: bool, pending: int, failed: int},
	 *   cleanup: array<string, int|string|null>,
	 *   video: array<string, int|string|bool|null>
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
		foreach (QueryResult::rows($qb->executeQuery()) as $row) {
			$counts[(string)$row['status']] = (int)$row['count'];
		}

		$previewBytes = 0;
		try {
			foreach ($this->appData->getFolder('watermarked-previews')->getDirectoryListing() as $file) {
				$previewBytes += $file->getSize();
			}
		} catch (\OCP\Files\NotFoundException) {
		}

		$notificationCounts = ['pending' => 0, 'failed' => 0];
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', $qb->func()->count('*', 'count'))
			->from('proofing_native_notify')
			->where($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'failed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->groupBy('status');
		foreach (QueryResult::rows($qb->executeQuery()) as $row) {
			$notificationCounts[(string)$row['status']] = (int)$row['count'];
		}

		return [
			'pendingUploads' => $counts['pending'],
			'awaitingReview' => $counts['awaiting_review'],
			'previewCacheBytes' => $previewBytes,
			'notifications' => [
				'available' => (bool)$this->apps->isInstalled('notifications'),
				'pending' => $notificationCounts['pending'],
				'failed' => $notificationCounts['failed'],
			],
			'video' => [...$this->videoDerivatives->health(), ...$this->videoTranscodes->availability()],
			'semantic' => $this->semanticIndex->health(),
			'livePush' => $this->livePush->health(),
			'cleanup' => $this->cleanupTelemetry->status(),
		];
	}
}
