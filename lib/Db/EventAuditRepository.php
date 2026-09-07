<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class EventAuditRepository {
	public function __construct(private IDBConnection $db) {
	}

	public function record(int $galleryId, ?int $recipientId, ?int $linkId, string $actorUid, string $action, string $outcome, ?string $reasonCode, int $now): void {
		if (preg_match('/^[a-z0-9_]{1,32}$/', $action) !== 1 || !in_array($outcome, ['success', 'failed'], true)) throw new \InvalidArgumentException('Invalid event audit entry');
		if ($reasonCode !== null && preg_match('/^[a-z0-9_]{1,48}$/', $reasonCode) !== 1) throw new \InvalidArgumentException('Invalid event audit reason');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_event_audit')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT), 'recipient_id' => $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT),
			'public_link_id' => $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT), 'actor_uid' => $qb->createNamedParameter($actorUid),
			'action' => $qb->createNamedParameter($action), 'outcome' => $qb->createNamedParameter($outcome), 'reason_code' => $qb->createNamedParameter($reasonCode),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function gallery(int $galleryId, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('recipient_id', 'public_link_id', 'actor_uid', 'action', 'outcome', 'reason_code', 'created_at')->from('proofing_event_audit')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')->setMaxResults(max(1, min(250, $limit)))->executeQuery());
	}
}
