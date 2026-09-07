<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Db\VideoDerivativeRepository;
use OCA\ProofingGallery\Db\SemanticIndexRepository;
use OCA\ProofingGallery\Db\LivePushRepository;
use OCA\ProofingGallery\Db\IntegrationOutboxRepository;
use OCA\ProofingGallery\Db\ReviewRoundRepository;
use OCA\ProofingGallery\Db\RetentionRepository;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\IDBConnection;

final class HealthService {
	/** @var array<string, mixed>|null */
	private ?array $operationalStatus = null;

	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
		private CleanupTelemetryService $cleanupTelemetry,
		private IAppManager $apps,
		private VideoDerivativeRepository $videoDerivatives,
		private VideoTranscodeService $videoTranscodes,
		private SemanticIndexRepository $semanticIndex,
		private LivePushRepository $livePush,
		private IntegrationOutboxRepository $integrationOutbox,
		private ReviewRoundRepository $reviewRounds,
		private RetentionRepository $retention,
		private BackgroundMaintenanceHealthService $maintenance,
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
		return [
			...$this->operationalStatus(),
			'notifications' => [
				...$this->notificationHealth(),
				'available' => (bool)$this->apps->isInstalled('notifications'),
			],
			'video' => [...$this->videoDerivatives->health(), ...$this->videoTranscodes->availability()],
			'integrations' => [
				'apps' => $this->integrationApps(),
				'outbox' => $this->integrationOutbox->health(),
			],
		];
	}

	/**
	 * Bounded, request-cached operational data for setup checks and metrics.
	 * This deliberately avoids external commands and optional-app discovery.
	 *
	 * @return array<string, mixed>
	 */
	public function operationalStatus(): array {
		if ($this->operationalStatus !== null) return $this->operationalStatus;

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
		foreach (['watermarked-previews', 'web-jpeg-downloads'] as $folderName) {
			try {
				foreach ($this->appData->getFolder($folderName)->getDirectoryListing() as $file) $previewBytes += $file->getSize();
			} catch (\OCP\Files\NotFoundException) {
			}
		}

		$this->operationalStatus = [
			'galleries' => $this->galleryCounts(),
			'pendingUploads' => $counts['pending'],
			'awaitingReview' => $counts['awaiting_review'],
			'previewCacheBytes' => $previewBytes,
			'notifications' => $this->notificationHealth(),
			'video' => $this->videoDerivatives->health(),
			'semantic' => $this->semanticIndex->health(),
			'livePush' => $this->livePush->health(),
			'integrations' => ['outbox' => $this->integrationOutbox->health()],
			'reviews' => $this->reviewRounds->health(gmdate('Y-m-d')),
			'mediaIndex' => $this->mediaIndexHealth(),
			'retention' => $this->retention->health(),
			'backlogs' => $this->backlogs(),
			'cleanup' => $this->cleanupTelemetry->status(),
			'maintenance' => $this->maintenance->status(),
		];
		return $this->operationalStatus;
	}

	/** @return array<string, int> */
	private function galleryCounts(): array {
		$counts = ['draft' => 0, 'published' => 0, 'archived' => 0];
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', $qb->func()->count('*', 'count'))->from('proofing_galleries')->groupBy('status');
		foreach (QueryResult::rows($qb->executeQuery()) as $row) {
			$status = (string)$row['status'];
			if (array_key_exists($status, $counts)) $counts[$status] = (int)$row['count'];
		}
		return $counts;
	}

	/** @return array{pending: int, failed: int} */
	private function notificationHealth(): array {
		$counts = ['pending' => 0, 'failed' => 0];
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', $qb->func()->count('*', 'count'))->from('proofing_native_notify')
			->where($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'failed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))->groupBy('status');
		foreach (QueryResult::rows($qb->executeQuery()) as $row) $counts[(string)$row['status']] = (int)$row['count'];
		return $counts;
	}

	/** @return array{running: int, stalled: int, lastCompletedAt: ?int} */
	private function mediaIndexHealth(): array {
		$running = $this->mediaScanCount();
		$stalled = $this->mediaScanCount(time() - 900);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('updated_at'))->from('proofing_media_scans')
			->where($qb->expr()->in('status', $qb->createNamedParameter(['ready', 'limit_reached'], IQueryBuilder::PARAM_STR_ARRAY)));
		$lastCompletedAt = $qb->executeQuery()->fetchOne();
		return [
			'running' => $running,
			'stalled' => $stalled,
			'lastCompletedAt' => $lastCompletedAt === false || $lastCompletedAt === null ? null : (int)$lastCompletedAt,
		];
	}

	private function mediaScanCount(?int $updatedBefore = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from('proofing_media_scans')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('running')));
		if ($updatedBefore !== null) $qb->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($updatedBefore, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return array{purges:array{scheduled:int,running:int,due:int,oldestExecuteAfter:?int},lifecycleDue:int,expiredGuests:int,mediaFolders:int} */
	private function backlogs(): array {
		$now = time();
		$purges = ['scheduled' => 0, 'running' => 0];
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', $qb->func()->count('*', 'count'))->from('proofing_purge_requests')
			->where($qb->expr()->in('status', $qb->createNamedParameter(['scheduled', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))->groupBy('status');
		foreach (QueryResult::rows($qb->executeQuery()) as $row) $purges[(string)$row['status']] = (int)$row['count'];
		$due = $this->db->getQueryBuilder();
		$due->selectAlias($due->func()->count(), 'count')->selectAlias($due->func()->min('execute_after'), 'oldest')->from('proofing_purge_requests')
			->where($due->expr()->in('status', $due->createNamedParameter(['scheduled', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($due->expr()->lte('execute_after', $due->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$dueRow = QueryResult::row($due->executeQuery());
		$lifecycle = $this->db->getQueryBuilder();
		$lifecycle->select($lifecycle->func()->count())->from('proofing_galleries')
			->where($lifecycle->expr()->in('status', $lifecycle->createNamedParameter(['draft', 'published'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($lifecycle->expr()->lte('lifecycle_next_at', $lifecycle->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$guests = $this->db->getQueryBuilder();
		$guests->select($guests->func()->count())->from('proofing_guests')->where($guests->expr()->lt('expires_at', $guests->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$folders = $this->db->getQueryBuilder();
		$folders->select($folders->func()->count())->from('proofing_media_scan_queue');
		return [
			'purges' => [...$purges, 'due' => (int)($dueRow['count'] ?? 0), 'oldestExecuteAfter' => isset($dueRow['oldest']) ? (int)$dueRow['oldest'] : null],
			'lifecycleDue' => (int)$lifecycle->executeQuery()->fetchOne(), 'expiredGuests' => (int)$guests->executeQuery()->fetchOne(),
			'mediaFolders' => (int)$folders->executeQuery()->fetchOne(),
		];
	}

	/** @return array<string, array{installed: bool, version: ?string}> */
	private function integrationApps(): array {
		$result = [];
		foreach ([
			'related_resources', 'workflowengine', 'context_chat', 'context_agent',
			'deck', 'calendar', 'spreed', 'notifications',
		] as $appId) {
			$installed = $this->apps->isInstalled($appId);
			$result[$appId] = [
				'installed' => $installed,
				'version' => $installed ? ($this->apps->getAppVersion($appId) ?: null) : null,
			];
		}
		return $result;
	}
}
