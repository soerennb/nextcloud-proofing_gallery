<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ActivityService {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $clock,
		private NotificationService $notifications,
	) {
	}

	/** @param array<string, mixed> $payload */
	public function record(Gallery $gallery, ?Guest $guest, string $type, array $payload): void {
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_events')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guest?->getId(), IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter(null),
			'event_type' => $qb->createNamedParameter($type),
			'payload' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$eventId = (int)$this->db->lastInsertId('proofing_events');
		$this->notifications->queue($gallery, $eventId, $type, $now);

	}

	/** @return list<array<string, mixed>> */
	public function list(Gallery $gallery, int $cursor = 0, string $type = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.*', 'g.display_name')
			->from('proofing_events', 'e')
			->leftJoin('e', 'proofing_guests', 'g', $qb->expr()->eq('e.guest_id', 'g.id'))
			->where($qb->expr()->eq('e.gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('e.id', $qb->createNamedParameter(max(0, $cursor), IQueryBuilder::PARAM_INT)))
			->orderBy('e.id', 'DESC')
			->setMaxResults(200);
		if ($type !== '') {
			$qb->andWhere($qb->expr()->like('e.event_type', $qb->createNamedParameter($this->db->escapeLikeParameter($type) . '%')));
		}
		return array_map(static fn (array $row): array => [
			'id' => (int)$row['id'],
			'type' => $row['event_type'],
			'actor' => $row['display_name'] ?? $row['actor_uid'] ?? 'Gallery manager',
			'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
			'createdAt' => (int)$row['created_at'],
		], $qb->executeQuery()->fetchAllAssociative());
	}

}
