<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class PurgeRepository {
	/** Tables are ordered so child rows disappear before their parents. */
	public const TABLES = [
		'proofing_annotations', 'proofing_selection_items', 'proofing_feedback', 'proofing_comments',
		'proofing_selections', 'proofing_guest_ratings', 'proofing_review_rounds', 'proofing_share_audit',
		'proofing_domains', 'proofing_link_roots', 'proofing_public_links', 'proofing_notify_queue', 'proofing_native_notify',
		'proofing_notify_subs', 'proofing_int_outbox', 'proofing_events', 'proofing_uploads',
		'proofing_live_push', 'proofing_retention_log', 'proofing_media_scan_queue', 'proofing_media_scans', 'proofing_media_index',
		'proofing_semantic_idx', 'proofing_versions', 'proofing_ext_resources',
		'proofing_collection_items', 'proofing_collections', 'proofing_pin_handoffs', 'proofing_event_audit', 'proofing_event_roots', 'proofing_event_recipients', 'proofing_event_waves', 'proofing_event_setups', 'proofing_summaries', 'proofing_guests',
		'proofing_managers', 'proofing_galleries',
	];

	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, int> */
	public function counts(int $galleryId): array {
		$result = [];
		foreach (self::TABLES as $table) {
			if ($table === 'proofing_galleries') continue;
			$result[$table] = $this->countTable($table, $galleryId);
		}
		return $result;
	}

	/** @param array<string, mixed> $snapshot */
	public function create(int $galleryId, string $title, string $requestedBy, array $snapshot, int $now, int $executeAfter): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_purge_requests')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'gallery_title' => $qb->createNamedParameter($title), 'requested_by' => $qb->createNamedParameter($requestedBy),
			'status' => $qb->createNamedParameter('scheduled'), 'stage' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'snapshot' => $qb->createNamedParameter(json_encode($snapshot, JSON_THROW_ON_ERROR)),
			'progress' => $qb->createNamedParameter('{}'), 'execute_after' => $qb->createNamedParameter($executeAfter, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT), 'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'completed_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return (int)$this->db->lastInsertId('proofing_purge_requests');
	}

	/** @return array<string, mixed>|null */
	public function active(int $galleryId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_purge_requests')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['scheduled', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return list<array<string, mixed>> */
	public function due(int $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_purge_requests')
			->where($qb->expr()->in('status', $qb->createNamedParameter(['scheduled', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('execute_after', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery());
	}

	public function cancel(int $id, int $galleryId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_purge_requests')->set('status', $qb->createNamedParameter('cancelled'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('scheduled')))->executeStatement() === 1;
	}

	/** @param array<string, int> $progress */
	public function advance(int $id, int $stage, array $progress, int $now, bool $complete): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_purge_requests')->set('stage', $qb->createNamedParameter($stage, IQueryBuilder::PARAM_INT))
			->set('progress', $qb->createNamedParameter(json_encode($progress, JSON_THROW_ON_ERROR)))
			->set('status', $qb->createNamedParameter($complete ? 'completed' : 'running'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('completed_at', $qb->createNamedParameter($complete ? $now : null, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function deleteBatch(string $table, int $galleryId, int $limit): int {
		if (!in_array($table, self::TABLES, true)) throw new \InvalidArgumentException('Unsupported purge table');
		if (in_array($table, ['proofing_media_scans', 'proofing_collections', 'proofing_summaries', 'proofing_galleries'], true)) {
			$qb = $this->db->getQueryBuilder();
			return $qb->delete($table)->where($qb->expr()->eq($table === 'proofing_galleries' ? 'id' : 'gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		if ($table === 'proofing_event_audit') return $this->deleteDirectGalleryBatch($table, $galleryId, $limit);
		if (in_array($table, ['proofing_selection_items', 'proofing_collection_items', 'proofing_link_roots', 'proofing_pin_handoffs', 'proofing_event_roots'], true)) return $this->deleteJoinedBatch($table, $galleryId, $limit);
		if ($table === 'proofing_notify_queue') return $this->deleteJoinedBatch($table, $galleryId, $limit);
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('id')->from($table)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery()));
		if ($ids === []) return 0;
		$delete = $this->db->getQueryBuilder();
		return $delete->delete($table)->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function exportRows(string $table, int $galleryId, int $afterId, int $limit): array {
		if (!in_array($table, self::TABLES, true) || $table === 'proofing_galleries') return [];
		if (in_array($table, ['proofing_media_scans', 'proofing_collections', 'proofing_summaries'], true)) {
			if ($afterId > 0) return [];
			$qb = $this->db->getQueryBuilder();
			$rows = QueryResult::rows($qb->select('*')->from($table)
				->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1)->executeQuery());
			if (isset($rows[0])) $rows[0]['__cursor'] = 1;
			return $rows;
		}
		if (in_array($table, ['proofing_selection_items', 'proofing_collection_items', 'proofing_link_roots', 'proofing_pin_handoffs', 'proofing_event_audit', 'proofing_event_roots'], true)) {
			if ($table === 'proofing_event_audit') {
				$qb = $this->db->getQueryBuilder();
				return QueryResult::rows($qb->select('*')->from($table)
					->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
					->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery());
			}
			$parentTable = match ($table) { 'proofing_selection_items' => 'proofing_selections', 'proofing_collection_items' => 'proofing_collections', 'proofing_pin_handoffs', 'proofing_event_roots' => 'proofing_event_waves', default => 'proofing_public_links' };
			$foreign = match ($table) { 'proofing_selection_items' => 'selection_id', 'proofing_collection_items' => 'collection_id', 'proofing_pin_handoffs', 'proofing_event_roots' => 'wave_id', default => 'public_link_id' };
			$parentId = $table === 'proofing_collection_items' ? 'gallery_id' : 'id';
			$qb = $this->db->getQueryBuilder();
			return QueryResult::rows($qb->select('child.*')->from($table, 'child')
				->innerJoin('child', $parentTable, 'parent', $qb->expr()->eq('child.' . $foreign, 'parent.' . $parentId))
				->where($qb->expr()->eq('parent.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('child.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('child.id', 'ASC')->setMaxResults($limit)->executeQuery());
		}
		if ($table === 'proofing_notify_queue') {
			$qb = $this->db->getQueryBuilder();
			return QueryResult::rows($qb->select('child.*')->from($table, 'child')
				->innerJoin('child', 'proofing_notify_subs', 'parent', $qb->expr()->eq('child.subscription_id', 'parent.id'))
				->where($qb->expr()->eq('parent.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('child.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('child.id', 'ASC')->setMaxResults($limit)->executeQuery());
		}
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from($table)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery());
	}

	/** @return list<string> */
	public function uploadIds(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('strval', QueryResult::column($qb->select('upload_id')->from('proofing_uploads')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))->executeQuery()));
	}

	/** @return list<int> */
	public function ownedGalleryIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('intval', QueryResult::column($qb->select('id')->from('proofing_galleries')
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($userId)))->orderBy('id', 'ASC')->executeQuery()));
	}

	public function deletePrincipal(string $type, string $id): int {
		$qb = $this->db->getQueryBuilder();
		$deleted = $qb->delete('proofing_managers')
			->where($qb->expr()->eq('principal_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($id)))->executeStatement();
		if ($type !== 'user') return $deleted;
		foreach ([
			['proofing_presets', 'owner_uid'],
			['proofing_inv_templates', 'owner_uid'],
			['proofing_media_cull', 'owner_uid'],
			['proofing_agent_requests', 'user_uid'],
			['proofing_notify_subs', 'user_uid'],
			['proofing_native_notify', 'user_uid'],
			['proofing_ext_resources', 'user_uid'],
		] as [$table, $column]) {
			$qb = $this->db->getQueryBuilder();
			$deleted += $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($id)))->executeStatement();
		}
		return $deleted;
	}

	/** @return array<string, list<array<string, mixed>>> */
	public function guestData(int $guestId): array {
		$result = [];
		foreach (['proofing_feedback', 'proofing_comments', 'proofing_selections', 'proofing_guest_ratings', 'proofing_events', 'proofing_uploads', 'proofing_share_audit'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$result[$table] = QueryResult::rows($qb->select('*')->from($table)
				->where($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')->executeQuery());
		}
		return $result;
	}

	/** @return list<string> */
	public function guestUploadIds(int $guestId): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('strval', QueryResult::column($qb->select('upload_id')->from('proofing_uploads')
			->where($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))->executeQuery()));
	}

	public function deleteGuestData(int $guestId): int {
		$this->db->beginTransaction();
		try {
			$comments = $this->guestParentIds('proofing_comments', $guestId);
			$selections = $this->guestParentIds('proofing_selections', $guestId);
			$deleted = $this->deleteIds('proofing_annotations', 'comment_id', $comments)
				+ $this->deleteIds('proofing_selection_items', 'selection_id', $selections);
			foreach (['proofing_feedback', 'proofing_comments', 'proofing_selections', 'proofing_guest_ratings', 'proofing_events', 'proofing_uploads', 'proofing_share_audit'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$deleted += $qb->delete($table)->where($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$deleted += $qb->delete('proofing_guests')->where($qb->expr()->eq('id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))->executeStatement();
			$this->db->commit();
			return $deleted;
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	/** @return list<int> */
	private function guestParentIds(string $table, int $guestId): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('intval', QueryResult::column($qb->select('id')->from($table)
			->where($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))->executeQuery()));
	}

	/** @param list<int> $ids */
	private function deleteIds(string $table, string $column, array $ids): int {
		if ($ids === []) return 0;
		$deleted = 0;
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$deleted += $qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		return $deleted;
	}

	private function countTable(string $table, int $galleryId): int {
		$qb = $this->db->getQueryBuilder();
		if ($table === 'proofing_selection_items') {
			$qb->select($qb->func()->count('child.id'))->from($table, 'child')->innerJoin('child', 'proofing_selections', 'parent', $qb->expr()->eq('child.selection_id', 'parent.id'));
		} elseif ($table === 'proofing_collection_items') {
			$qb->select($qb->func()->count('child.id'))->from($table, 'child')->innerJoin('child', 'proofing_collections', 'parent', $qb->expr()->eq('child.collection_id', 'parent.gallery_id'));
		} elseif ($table === 'proofing_notify_queue') {
			$qb->select($qb->func()->count('child.id'))->from($table, 'child')->innerJoin('child', 'proofing_notify_subs', 'parent', $qb->expr()->eq('child.subscription_id', 'parent.id'));
		} elseif ($table === 'proofing_link_roots') {
			$qb->select($qb->func()->count('child.id'))->from($table, 'child')->innerJoin('child', 'proofing_public_links', 'parent', $qb->expr()->eq('child.public_link_id', 'parent.id'));
		} elseif ($table === 'proofing_pin_handoffs') {
			$qb->select($qb->func()->count('child.id'))->from($table, 'child')->innerJoin('child', 'proofing_event_waves', 'parent', $qb->expr()->eq('child.wave_id', 'parent.id'));
		} elseif ($table === 'proofing_event_roots') {
			$qb->select($qb->func()->count('child.id'))->from($table, 'child')->innerJoin('child', 'proofing_event_waves', 'parent', $qb->expr()->eq('child.wave_id', 'parent.id'));
		} else {
			$qb->select($qb->func()->count())->from($table, 'parent');
		}
		$qb->where($qb->expr()->eq('parent.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function deleteJoinedBatch(string $table, int $galleryId, int $limit): int {
		$parentTable = match ($table) {
			'proofing_selection_items' => 'proofing_selections',
			'proofing_collection_items' => 'proofing_collections',
			'proofing_link_roots' => 'proofing_public_links',
			'proofing_pin_handoffs' => 'proofing_event_waves',
			'proofing_event_roots' => 'proofing_event_waves',
			default => 'proofing_notify_subs',
		};
		$foreign = match ($table) {
			'proofing_selection_items' => 'selection_id',
			'proofing_collection_items' => 'collection_id',
			'proofing_link_roots' => 'public_link_id',
			'proofing_pin_handoffs' => 'wave_id',
			'proofing_event_roots' => 'wave_id',
			default => 'subscription_id',
		};
		$parentId = $table === 'proofing_collection_items' ? 'gallery_id' : 'id';
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('child.id')->from($table, 'child')
			->innerJoin('child', $parentTable, 'parent', $qb->expr()->eq('child.' . $foreign, 'parent.' . $parentId))
			->where($qb->expr()->eq('parent.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('child.id', 'ASC')->setMaxResults($limit)->executeQuery()));
		if ($ids === []) return 0;
		$delete = $this->db->getQueryBuilder();
		return $delete->delete($table)->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
	}

	private function deleteDirectGalleryBatch(string $table, int $galleryId, int $limit): int {
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('id')->from($table)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery()));
		if ($ids === []) return 0;
		$delete = $this->db->getQueryBuilder();
		return $delete->delete($table)->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
	}
}
