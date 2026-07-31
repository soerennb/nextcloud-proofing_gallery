<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Service\CollectionAnchorReferences;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Gallery> */
final class GalleryMapper extends QBMapper implements CollectionAnchorReferences {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_galleries', Gallery::class);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function find(int $id): Gallery {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findOwned(int $id, string $ownerUid): Gallery {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));

		return $this->findEntity($qb);
	}

	/** @return list<Gallery> */
	public function findAllOwned(string $ownerUid, int $limit, int $offset, bool $archived, string $search = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->orderBy('updated_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$this->applyFilters($qb, $archived, $search);

		return $this->findEntities($qb);
	}

	public function countOwned(string $ownerUid, bool $archived, string $search = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())
			->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
		$this->applyFilters($qb, $archived, $search);

		return (int)$qb->executeQuery()->fetchOne();
	}

	public function slugExists(string $ownerUid, string $slug): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())
			->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->andWhere($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	public function isReferenced(int $folderId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())
			->from($this->tableName)
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter('collection')));

		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findByShareToken(string $token): Gallery {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('share_token', $qb->createNamedParameter($token)));

		return $this->findEntity($qb);
	}

	private function applyFilters(IQueryBuilder $qb, bool $archived, string $search): void {
		$archiveStatus = $qb->createNamedParameter('archived');
		$qb->andWhere($archived
			? $qb->expr()->eq('status', $archiveStatus)
			: $qb->expr()->neq('status', $archiveStatus));
		if ($search !== '') {
			$needle = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->iLike('title', $qb->createNamedParameter($needle)));
		}
	}
}
