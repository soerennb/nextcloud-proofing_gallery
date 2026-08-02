<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<PublicLink> */
final class PublicLinkMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_public_links', PublicLink::class);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function find(int $id): PublicLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findByToken(string $token): PublicLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findPrimary(int $galleryId): PublicLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_primary', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		return $this->findEntity($qb);
	}

	/** @return list<PublicLink> */
	public function findForGallery(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('is_primary', 'DESC')->addOrderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<PublicLink> */
	public function findUsableForGallery(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['active', 'suspended'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('is_primary', 'DESC')->addOrderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	public function countUsableForGallery(int $galleryId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['active', 'suspended'], IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function clearPrimary(int $galleryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('is_primary', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
