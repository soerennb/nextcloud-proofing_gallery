<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<DesignAsset> */
final class DesignAssetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_design_assets', DesignAsset::class);
	}

	public function findOwned(string $publicId, string $ownerUid): DesignAsset {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
			->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
		return $this->findEntity($qb);
	}

	/** @return list<DesignAsset> */
	public function findAllOwned(string $ownerUid, ?string $kind = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->orderBy('created_at', 'DESC');
		if ($kind !== null) $qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		return $this->findEntities($qb);
	}
}
