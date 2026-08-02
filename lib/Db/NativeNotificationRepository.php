<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class NativeNotificationRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function find(int $galleryId, string $userUid, string $category): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_native_notify')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	/**
	 * Atomically creates or coalesces an attention state.
	 *
	 * @return array{id: int, dispatch: bool}|null
	 */
	public function signal(int $galleryId, string $userUid, string $category, ?int $eventId, int $now): ?array {
		$row = $this->find($galleryId, $userUid, $category);
		if ($row === null) {
			$qb = $this->db->getQueryBuilder();
			try {
				$qb->insert('proofing_native_notify')->values([
					'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
					'user_uid' => $qb->createNamedParameter($userUid),
					'category' => $qb->createNamedParameter($category),
					'event_count' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'latest_event_id' => $eventId === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
					'status' => $qb->createNamedParameter('pending'),
					'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
					'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'available_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
				$row = $this->find($galleryId, $userUid, $category);
				return $row === null ? null : ['id' => (int)$row['id'], 'dispatch' => true];
			} catch (Exception) {
				// A concurrent signal won the unique gallery/user/category insert.
				$row = $this->find($galleryId, $userUid, $category);
			}
		}
		if ($row === null) return null;
		$wasActive = (bool)$row['active'];
		$retryFailed = $wasActive && (string)$row['status'] === 'failed';
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_native_notify')
			->set('event_count', $wasActive ? $qb->createFunction('event_count + 1') : $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('latest_event_id', $eventId === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT))
			->set('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->set('status', $qb->createNamedParameter($wasActive && !$retryFailed ? (string)$row['status'] : 'pending'))
			->set('attempts', $qb->createNamedParameter($wasActive && !$retryFailed ? (int)$row['attempts'] : 0, IQueryBuilder::PARAM_INT))
			->set('available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return ['id' => (int)$row['id'], 'dispatch' => !$wasActive || $retryFailed];
	}

	/** @return list<int> */
	public function pendingIds(int $now): array {
		$this->recoverStaleClaims($now);
		$qb = $this->db->getQueryBuilder();
		return array_map('intval', QueryResult::column($qb->select('id')->from('proofing_native_notify')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('id')->setMaxResults(100)->executeQuery()));
	}

	/** @return array<string, mixed>|null */
	public function activeState(int $id, string $userUid): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_native_notify')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	public function dismiss(int $id, string $userUid, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_native_notify')
			->set('active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('event_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->executeStatement();
	}

	/** @return list<int> */
	public function activeIds(int $galleryId, string $userUid): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('intval', QueryResult::column($qb->select('id')->from('proofing_native_notify')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery()));
	}

	/** @return array<string, mixed>|null */
	public function claim(int $id, int $now): ?array {
		$qb = $this->db->getQueryBuilder();
		$count = $qb->update('proofing_native_notify')->set('status', $qb->createNamedParameter('sending'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeStatement();
		if ($count !== 1) return null;
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_native_notify')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	public function markDelivered(int $id, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_native_notify')->set('status', $qb->createNamedParameter('delivered'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function markFailedAttempt(int $id, int $attempts, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_native_notify')
			->set('status', $qb->createNamedParameter($attempts >= 5 ? 'failed' : 'pending'))
			->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
			->set('available_at', $qb->createNamedParameter($now + min(3600, 300 * (2 ** max(0, $attempts - 1))), IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function recoverStaleClaims(int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_native_notify')->set('status', $qb->createNamedParameter('pending'))
			->where($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($now - 900, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
