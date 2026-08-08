<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ActivityRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $payload */
	public function insert(int $galleryId, ?int $guestId, string $type, array $payload, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_events')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter(null),
			'event_type' => $qb->createNamedParameter($type),
			'payload' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return (int)$this->db->lastInsertId('proofing_events');
	}

	public function existsWithDedupeKey(int $galleryId, string $type, string $dedupeKey): bool {
		$qb = $this->db->getQueryBuilder();
		$value = $qb->select('id')->from('proofing_events')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->like('payload', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter('"dedupeKey":"' . $dedupeKey . '"') . '%')))
			->setMaxResults(1)->executeQuery()->fetchOne();
		return $value !== false;
	}

	/** @return list<array<string, mixed>> */
	public function list(int $galleryId, int $cursor, string $type): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.*', 'g.display_name')->from('proofing_events', 'e')
			->leftJoin('e', 'proofing_guests', 'g', $qb->expr()->eq('e.guest_id', 'g.id'))
			->where($qb->expr()->eq('e.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('e.id', $qb->createNamedParameter(max(0, $cursor), IQueryBuilder::PARAM_INT)))
			->orderBy('e.id', 'DESC')->setMaxResults(200);
		if ($type !== '') {
			$qb->andWhere($qb->expr()->like('e.event_type', $qb->createNamedParameter($this->db->escapeLikeParameter($type) . '%')));
		}
		return QueryResult::rows($qb->executeQuery());
	}

	/** @return list<array<string, mixed>> */
	public function page(int $galleryId, ?int $beforeId, string $type, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.*', 'g.display_name')->from('proofing_events', 'e')
			->leftJoin('e', 'proofing_guests', 'g', $qb->expr()->eq('e.guest_id', 'g.id'))
			->where($qb->expr()->eq('e.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('e.id', 'DESC')->setMaxResults(max(1, min(101, $limit)));
		if ($beforeId !== null) $qb->andWhere($qb->expr()->lt('e.id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		if ($type !== '') $qb->andWhere($qb->expr()->like('e.event_type', $qb->createNamedParameter($this->db->escapeLikeParameter($type) . '%')));
		return QueryResult::rows($qb->executeQuery());
	}

	public function countGallery(int $galleryId, string $type): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from('proofing_events')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		if ($type !== '') $qb->andWhere($qb->expr()->like('event_type', $qb->createNamedParameter($this->db->escapeLikeParameter($type) . '%')));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
