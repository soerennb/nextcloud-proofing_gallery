<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class LifecycleRepository {
	private const ORPHAN_TABLES = [
		'proofing_events', 'proofing_uploads', 'proofing_collections', 'proofing_notify_subs',
		'proofing_native_notify', 'proofing_media_index', 'proofing_media_scans', 'proofing_media_scan_queue', 'proofing_public_links',
		'proofing_guest_ratings', 'proofing_share_audit', 'proofing_live_push', 'proofing_domains',
		'proofing_feedback', 'proofing_comments', 'proofing_annotations', 'proofing_selections',
		'proofing_managers', 'proofing_semantic_idx', 'proofing_summaries', 'proofing_versions',
		'proofing_review_rounds', 'proofing_ext_resources', 'proofing_int_outbox',
		'proofing_retention_log',
	];
	private const GALLERY_PRIMARY_KEY_TABLES = ['proofing_collections', 'proofing_summaries', 'proofing_media_scans'];

	public function __construct(private IDBConnection $db) {
	}

	public function deleteOldRows(string $table, string $column, int $before, int $limit): int {
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('id')->from($table)
			->where($qb->expr()->lt($column, $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->orderBy($column, 'ASC')->addOrderBy('id', 'ASC')->setMaxResults($limit)->executeQuery()));
		if ($ids === []) return 0;
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($table)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/** @param list<string> $statuses */
	public function deleteOldRowsWithStatuses(string $table, string $column, array $statuses, int $before, int $limit): int {
		if ($statuses === []) return 0;
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('id')->from($table)
			->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt($column, $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->orderBy($column, 'ASC')->addOrderBy('id', 'ASC')->setMaxResults($limit)->executeQuery()));
		if ($ids === []) return 0;
		$delete = $this->db->getQueryBuilder();
		return $delete->delete($table)
			->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	/** @return list<array{id: int, upload_id: string, status: string}> */
	public function expiredUploads(int $pendingBefore, int $completedBefore, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('id', 'upload_id', 'status')->from('proofing_uploads')
			->where($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('pending')),
					$qb->expr()->lt('updated_at', $qb->createNamedParameter($pendingBefore, IQueryBuilder::PARAM_INT)),
				),
				$qb->expr()->andX(
					$qb->expr()->in('status', $qb->createNamedParameter(['accepted', 'rejected'], IQueryBuilder::PARAM_STR_ARRAY)),
					$qb->expr()->lt('updated_at', $qb->createNamedParameter($completedBefore, IQueryBuilder::PARAM_INT)),
				),
			))->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery());
		return array_map(static fn (array $row): array => [
			'id' => (int)$row['id'], 'upload_id' => (string)$row['upload_id'], 'status' => (string)$row['status'],
		], $rows);
	}

	/** @param list<int> $ids */
	public function deleteUploads(array $ids): int {
		if ($ids === []) return 0;
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('proofing_uploads')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	public function cleanupOrphans(): int {
		$deleted = 0;
		foreach (self::ORPHAN_TABLES as $table) {
			$deleted += in_array($table, self::GALLERY_PRIMARY_KEY_TABLES, true)
				? $this->deleteMissingGalleryPrimaryRows($table)
				: $this->deleteMissingParents($table, 'gallery_id', 'proofing_galleries');
		}
		$deleted += $this->deleteMissingParents('proofing_collection_items', 'collection_id', 'proofing_collections', 'gallery_id');
		$deleted += $this->deleteMissingParents('proofing_selection_items', 'selection_id', 'proofing_selections');
		$deleted += $this->deleteMissingParents('proofing_annotations', 'comment_id', 'proofing_comments');
		return $deleted;
	}

	private function deleteMissingGalleryPrimaryRows(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('child.gallery_id')->from($table, 'child')
			->leftJoin('child', 'proofing_galleries', 'parent', $qb->expr()->eq('child.gallery_id', 'parent.id'))
			->where($qb->expr()->isNull('parent.id'))
			->orderBy('child.gallery_id', 'ASC')->setMaxResults(1000)->executeQuery()));
		if ($ids === []) return 0;
		$delete = $this->db->getQueryBuilder();
		return $delete->delete($table)
			->where($delete->expr()->in('gallery_id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	private function deleteMissingParents(string $table, string $parentColumn, string $parentTable, string $parentIdColumn = 'id'): int {
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('child.id')->from($table, 'child')
			->leftJoin('child', $parentTable, 'parent', $qb->expr()->eq('child.' . $parentColumn, 'parent.' . $parentIdColumn))
			->where($qb->expr()->isNull('parent.' . $parentIdColumn))
			->orderBy('child.id', 'ASC')->setMaxResults(1000)->executeQuery()));
		if ($ids === []) return 0;
		$delete = $this->db->getQueryBuilder();
		return $delete->delete($table)
			->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

}
