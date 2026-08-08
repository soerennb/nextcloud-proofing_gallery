<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Guest> */
final class GuestMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_guests', Guest::class);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findBySession(int $galleryId, string $sessionHash, int $now): Guest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('session_hash', $qb->createNamedParameter($sessionHash)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	public function deleteExpired(int $now, int $limit = 1000): int {
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', QueryResult::column($qb->select('id')->from($this->tableName)
			->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('expires_at', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)))->executeQuery()));
		if ($ids === []) return 0;
		$delete = $this->db->getQueryBuilder();
		return $delete->delete($this->tableName)
			->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}

	public function countActiveForGallery(int $galleryId, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
