<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\LifecycleRepository;
use OCA\ProofingGallery\Db\VideoDerivativeRepository;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;

final class LifecycleService {
	private const BATCH_SIZE = 1000;

	public function __construct(
		private LifecycleRepository $repository,
		private ITimeFactory $clock,
		private IAppData $appData,
		private PolicyService $policies,
		private CollectionAnchorReconciler $collectionAnchors,
		private VersionService $versions,
		private GalleryMapper $galleries,
		private PublicShareService $shares,
		private CapabilityPolicyService $capabilities,
		private NativeNotificationService $notifications,
		private ActivityService $activity,
		private VideoDerivativeRepository $videoDerivatives,
		private LifecycleScheduleService $lifecycleSchedule,
		private RetentionHandoffService $retention,
	) {
	}

	/** @return array<string, int> */
	public function cleanup(): array {
		$now = $this->clock->getTime();
		$events = $this->repository->deleteOldRows(
			'proofing_events',
			'created_at',
			$now - $this->policies->get('eventRetentionDays') * 86400, self::BATCH_SIZE,
		);
		$shareAudit = $this->repository->deleteOldRows(
			'proofing_share_audit',
			'created_at',
			$now - $this->policies->get('shareAuditRetentionDays') * 86400, self::BATCH_SIZE,
		);
		$notificationQueue = $this->repository->deleteOldRowsWithStatuses(
			'proofing_notify_queue', 'updated_at', ['sent', 'failed'],
			$now - $this->policies->get('notificationQueueRetentionDays') * 86400, self::BATCH_SIZE,
		);
		$deadLetters = $this->repository->deleteOldRowsWithStatuses(
			'proofing_int_outbox', 'dead_at', ['dead'],
			$now - $this->policies->get('deadLetterRetentionDays') * 86400, self::BATCH_SIZE,
		);
		$agentRequests = $this->repository->deleteOldRows(
			'proofing_agent_requests', 'expires_at', $now, self::BATCH_SIZE,
		);
		$uploads = $this->cleanupUploads($now) + $this->cleanupOwnerUploads(
			$now - $this->policies->get('pendingUploadRetentionHours') * 3600,
		);
		$previews = $this->cleanupPreviewCache(
			$now - $this->policies->get('previewRetentionDays') * 86400,
		);
		$video = $this->cleanupVideoDerivatives($now - $this->policies->get('videoDerivativeRetentionDays') * 86400);
		$orphans = $this->cleanupOrphanMetadata();
		$collectionAnchors = $this->collectionAnchors->reconcile(false)['deleted'];
		$versions = $this->versions->cleanupExpired(self::BATCH_SIZE);
		$suspended = 0;
		foreach ($this->galleries->findArchivedWithActiveLinks() as $gallery) {
			$suspended += $this->shares->reconcileArchived($gallery);
		}
		['revoked' => $revoked, 'archived' => $archived, 'processed' => $lifecycleProcessed] = $this->automateGalleries($now);
		$remaining = (int)($events === self::BATCH_SIZE
			|| $shareAudit === self::BATCH_SIZE
			|| $notificationQueue === self::BATCH_SIZE
			|| $deadLetters === self::BATCH_SIZE
			|| $agentRequests === self::BATCH_SIZE
			|| $uploads >= self::BATCH_SIZE
			|| $previews === self::BATCH_SIZE
			|| $video === self::BATCH_SIZE
			|| $versions === self::BATCH_SIZE
			|| $orphans >= self::BATCH_SIZE
			|| $lifecycleProcessed === 200);
		return compact('events', 'shareAudit', 'notificationQueue', 'deadLetters', 'agentRequests', 'uploads', 'previews', 'video', 'versions', 'orphans', 'collectionAnchors', 'suspended', 'revoked', 'archived', 'lifecycleProcessed', 'remaining');
	}

	/** @return array{revoked: int, archived: int, processed: int} */
	private function automateGalleries(int $now): array {
		if (!$this->capabilities->feature('lifecycleAutomation')) return ['revoked' => 0, 'archived' => 0, 'processed' => 0];
		$revoked = 0;
		$archived = 0;
		$processed = 0;
		foreach ($this->galleries->findLifecycleCandidates($now) as $gallery) {
			$processed++;
			$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$rule = $settings->lifecycle;
			if (!$rule->enabled) {
				$this->lifecycleSchedule->project($gallery, $now, false);
				$this->galleries->updateLifecycleProjection($gallery);
				continue;
			}

			$revokeAt = $this->lifecycleSchedule->revokeTimestamp($gallery->getCompletedAt(), $rule);
			if ($gallery->getShareToken() !== null && $revokeAt !== null && $now < $revokeAt) {
				foreach (array_values(array_unique($rule->reminderDays)) as $days) {
					if ($now < $revokeAt - $days * 86400) continue;
					$key = $revokeAt . ':' . $days;
					if ($this->activity->recordOnce($gallery, 'lifecycle.reminder', $key, ['days' => $days, 'actionAt' => $revokeAt])) {
						$this->notifications->signalCategory((int)$gallery->getId(), $gallery->getOwnerUid(), 'lifecycle');
					}
				}
			}
			if ($gallery->getShareToken() !== null && $revokeAt !== null && $revokeAt <= $now) {
				$this->shares->revoke($gallery);
				$this->activity->recordOnce($gallery, 'lifecycle.revoked', (string)$revokeAt, ['actionAt' => $revokeAt]);
				$this->notifications->signalCategory((int)$gallery->getId(), $gallery->getOwnerUid(), 'revoked');
				$revoked++;
			}
			if ($gallery->getShareToken() === null && $gallery->getRevokedAt() !== null
				&& $gallery->getRevokedAt() + $rule->archiveAfterDays * 86400 <= $now) {
				$gallery->setStatus('archived');
				$gallery->setArchivedAt($now);
				$gallery->setUpdatedAt($now);
				$gallery->setRevision($gallery->getRevision() + 1);
				$this->galleries->update($gallery);
				$this->retention->assignOnArchive($gallery, 'system:lifecycle');
				$this->activity->recordOnce($gallery, 'lifecycle.archived', (string)$gallery->getArchivedAt(), ['actionAt' => $gallery->getArchivedAt()]);
				$archived++;
			}
			$this->lifecycleSchedule->project($gallery, $now, false);
			$this->galleries->updateLifecycleProjection($gallery);
		}
		return compact('revoked', 'archived', 'processed');
	}

	private function cleanupUploads(int $now): int {
		$rows = $this->repository->expiredUploads(
			$now - $this->policies->get('pendingUploadRetentionHours') * 3600,
			$now - $this->policies->get('completedUploadRetentionDays') * 86400,
			self::BATCH_SIZE,
		);
		if ($rows === []) {
			return 0;
		}
		foreach ($rows as $row) {
			if ($row['status'] === 'pending') {
				try {
					$this->appData->getFolder('guest-uploads')->getFolder($row['upload_id'])->delete();
				} catch (\OCP\Files\NotFoundException) {
				}
			}
		}
		$ids = array_map(static fn (array $row): int => (int)$row['id'], $rows);
		return $this->repository->deleteUploads($ids);
	}

	private function cleanupOwnerUploads(int $before): int {
		try {
			$root = $this->appData->getFolder('owner-uploads');
		} catch (\OCP\Files\NotFoundException) {
			return 0;
		}
		$deleted = 0;
		foreach ($root->getDirectoryListing() as $session) {
			if ($deleted >= self::BATCH_SIZE || !$session instanceof \OCP\Files\SimpleFS\ISimpleFolder) continue;
			try {
				if ($session->getFile('manifest.json')->getMTime() >= $before) continue;
				$session->delete();
				$deleted++;
			} catch (\OCP\Files\NotFoundException) {
				$session->delete();
				$deleted++;
			}
		}
		return $deleted;
	}

	private function cleanupPreviewCache(int $before): int {
		try {
			$folder = $this->appData->getFolder('watermarked-previews');
		} catch (\OCP\Files\NotFoundException) {
			return 0;
		}
		$deleted = 0;
		foreach ($folder->getDirectoryListing() as $file) {
			if ($deleted >= self::BATCH_SIZE) {
				break;
			}
			if ($file->getMTime() < $before) {
				$file->delete();
				$deleted++;
			}
		}
		return $deleted;
	}

	private function cleanupOrphanMetadata(): int {
		return $this->repository->cleanupOrphans();
	}

	private function cleanupVideoDerivatives(int $before): int {
		$rows = $this->videoDerivatives->expired($before, self::BATCH_SIZE);
		if ($rows === []) return 0;
		try {
			$folder = $this->appData->getFolder('video-derivatives');
			foreach ($rows as $row) foreach ([$row['storage_key'], $row['poster_key']] as $key) {
				if (!is_string($key)) continue;
				try {
					$folder->getFile($key)->delete();
				} catch (\OCP\Files\NotFoundException) {
				}
			}
		} catch (\OCP\Files\NotFoundException) {
		}
		return $this->videoDerivatives->delete(array_column($rows, 'id'));
	}
}
