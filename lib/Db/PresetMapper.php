<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Preset> */
final class PresetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_presets', Preset::class);
	}

	/** @return list<Preset> */
	public function findAllOwned(string $ownerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	public function countOwned(string $ownerUid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findOwned(int $id, string $ownerUid): Preset {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
		return $this->findEntity($qb);
	}

	public function nameExists(string $ownerUid, string $name, ?int $exceptId = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		if ($exceptId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)));
		}
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
}
