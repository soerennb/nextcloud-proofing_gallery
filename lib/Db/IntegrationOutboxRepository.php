<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class IntegrationOutboxRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $payload */
	public function enqueue(?int $galleryId, string $eventType, array $payload, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_int_outbox')->values([
			'gallery_id' => $galleryId === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'event_type' => $qb->createNamedParameter($eventType),
			'payload_json' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
			'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter('pending'),
			'available_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return (int)$this->db->lastInsertId('proofing_int_outbox');
	}

	/** @return list<array<string, mixed>> */
	public function ready(int $now, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_int_outbox')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults(max(1, min(500, $limit)))->executeQuery());
	}

	public function delete(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_int_outbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function retry(int $id, int $attempts, int $now, ?string $errorCode = null): void {
		$qb = $this->db->getQueryBuilder();
		$update = $qb->update('proofing_int_outbox')
			->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
			->set('status', $qb->createNamedParameter($attempts >= 10 ? 'dead' : 'pending'))
			->set('available_at', $qb->createNamedParameter($now + min(3600, 30 * (2 ** min(7, $attempts))), IQueryBuilder::PARAM_INT))
			->set('dead_at', $qb->createNamedParameter($attempts >= 10 ? $now : null, IQueryBuilder::PARAM_INT))
			->set('last_error_code', $qb->createNamedParameter($errorCode === null ? null : mb_substr($errorCode, 0, 96)))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$update->executeStatement();
	}

	/** @return array{pending: int, retrying: int, dead: int, oldestCreatedAt: ?int, maxAttempts: int} */
	public function health(): array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb
			->select($qb->func()->count('*', 'pending'))
			->selectAlias($qb->func()->sum('attempts'), 'attempts_sum')
			->selectAlias($qb->func()->min('created_at'), 'oldest_created_at')
			->selectAlias($qb->func()->max('attempts'), 'max_attempts')
			->from('proofing_int_outbox')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->executeQuery());
		$pending = (int)($row['pending'] ?? 0);
		$deadQb = $this->db->getQueryBuilder();
		$deadQb->select($deadQb->func()->count())->from('proofing_int_outbox')
			->where($deadQb->expr()->eq('status', $deadQb->createNamedParameter('dead')));
		$dead = (int)$deadQb->executeQuery()->fetchOne();
		if ($pending === 0) {
			return ['pending' => 0, 'retrying' => 0, 'dead' => $dead, 'oldestCreatedAt' => null, 'maxAttempts' => 0];
		}

		$retrying = $this->db->getQueryBuilder();
		$retrying->select($retrying->func()->count())
			->from('proofing_int_outbox')
			->where($retrying->expr()->eq('status', $retrying->createNamedParameter('pending')))
			->andWhere($retrying->expr()->gt('attempts', $retrying->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return [
			'pending' => $pending,
			'retrying' => (int)$retrying->executeQuery()->fetchOne(),
			'dead' => $dead,
			'oldestCreatedAt' => isset($row['oldest_created_at']) ? (int)$row['oldest_created_at'] : null,
			'maxAttempts' => (int)($row['max_attempts'] ?? 0),
		];
	}
}
