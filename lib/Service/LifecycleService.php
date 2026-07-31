<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\IDBConnection;

final class LifecycleService {
	private const BATCH_SIZE = 1000;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $clock,
		private IAppData $appData,
		private PolicyService $policies,
		private CollectionAnchorReconciler $collectionAnchors,
		private VersionService $versions,
		private GalleryMapper $galleries,
		private PublicShareService $shares,
	) {
	}

	/** @return array<string, int> */
	public function cleanup(): array {
		$now = $this->clock->getTime();
		$events = $this->deleteOldRows(
			'proofing_events',
			'created_at',
			$now - $this->policies->get('eventRetentionDays') * 86400,
		);
		$uploads = $this->cleanupUploads($now);
		$previews = $this->cleanupPreviewCache(
			$now - $this->policies->get('previewRetentionDays') * 86400,
		);
		$orphans = $this->cleanupOrphanMetadata();
		$collectionAnchors = $this->collectionAnchors->reconcile(false)['deleted'];
		$versions = $this->versions->cleanupExpired(self::BATCH_SIZE);
		['revoked' => $revoked, 'archived' => $archived] = $this->automateGalleries($now);
		return compact('events', 'uploads', 'previews', 'versions', 'orphans', 'collectionAnchors', 'revoked', 'archived');
	}

	/** @return array{revoked: int, archived: int} */
	private function automateGalleries(int $now): array {
		$revoked = 0;
		$archived = 0;
		foreach ($this->galleries->findLifecycleCandidates() as $gallery) {
			$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$rule = $settings->lifecycle;
			if (!$rule['enabled']) continue;

			if ($gallery->getShareToken() !== null && $this->revokeDue($gallery->getCompletedAt(), $rule, $now)) {
				$this->shares->revoke($gallery);
				$revoked++;
			}
			if ($gallery->getShareToken() === null && $gallery->getRevokedAt() !== null
				&& $gallery->getRevokedAt() + $rule['archiveAfterDays'] * 86400 <= $now) {
				$gallery->setStatus('archived');
				$gallery->setArchivedAt($now);
				$gallery->setUpdatedAt($now);
				$gallery->setRevision($gallery->getRevision() + 1);
				$this->galleries->update($gallery);
				$archived++;
			}
		}
		return compact('revoked', 'archived');
	}

	/** @param array<string, mixed> $rule */
	private function revokeDue(?int $completedAt, array $rule, int $now): bool {
		if ($rule['trigger'] === 'after_completion') {
			return $completedAt !== null && $completedAt + $rule['revokeAfterDays'] * 86400 <= $now;
		}
		if ($rule['revokeAt'] === '') return false;
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rule['revokeAt'], new \DateTimeZone('UTC'));
		return $date !== false && $date->setTime(23, 59, 59)->getTimestamp() <= $now;
	}

	private function deleteOldRows(string $table, string $column, int $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($table)
			->where($qb->expr()->lt($column, $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults(self::BATCH_SIZE);
		$ids = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
		if ($ids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}

	private function cleanupUploads(int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'upload_id', 'status')->from('proofing_uploads')
			->where($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('pending')),
					$qb->expr()->lt('updated_at', $qb->createNamedParameter(
						$now - $this->policies->get('pendingUploadRetentionHours') * 3600,
						IQueryBuilder::PARAM_INT,
					)),
				),
				$qb->expr()->andX(
					$qb->expr()->in('status', $qb->createNamedParameter(['accepted', 'rejected'], IQueryBuilder::PARAM_STR_ARRAY)),
					$qb->expr()->lt('updated_at', $qb->createNamedParameter(
						$now - $this->policies->get('completedUploadRetentionDays') * 86400,
						IQueryBuilder::PARAM_INT,
					)),
				),
			))
			->orderBy('id', 'ASC')->setMaxResults(self::BATCH_SIZE);
		$rows = $qb->executeQuery()->fetchAllAssociative();
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
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_uploads')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
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
		$deleted = 0;
		foreach (['proofing_events', 'proofing_uploads', 'proofing_collections'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('gallery_id')->from($table)->setMaxResults(100);
			foreach ($qb->executeQuery()->fetchFirstColumn() as $galleryId) {
				$check = $this->db->getQueryBuilder();
				$check->select('id')->from('proofing_galleries')
					->where($check->expr()->eq('id', $check->createNamedParameter((int)$galleryId, IQueryBuilder::PARAM_INT)));
				if ($check->executeQuery()->fetchOne() !== false) {
					continue;
				}
				$delete = $this->db->getQueryBuilder();
				$delete->delete($table)
					->where($delete->expr()->eq('gallery_id', $delete->createNamedParameter((int)$galleryId, IQueryBuilder::PARAM_INT)));
				$deleted += $delete->executeStatement();
			}
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('collection_id')->from('proofing_collection_items')->setMaxResults(100);
		foreach ($qb->executeQuery()->fetchFirstColumn() as $collectionId) {
			$check = $this->db->getQueryBuilder();
			$check->select('gallery_id')->from('proofing_collections')
				->where($check->expr()->eq('gallery_id', $check->createNamedParameter((int)$collectionId, IQueryBuilder::PARAM_INT)));
			if ($check->executeQuery()->fetchOne() !== false) {
				continue;
			}
			$delete = $this->db->getQueryBuilder();
			$delete->delete('proofing_collection_items')
				->where($delete->expr()->eq('collection_id', $delete->createNamedParameter((int)$collectionId, IQueryBuilder::PARAM_INT)));
			$deleted += $delete->executeStatement();
		}
		return $deleted;
	}
}
