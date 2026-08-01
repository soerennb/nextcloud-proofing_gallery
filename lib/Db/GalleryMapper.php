<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Exception\GalleryConflictException;
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

	/**
	 * Persist the user-editable gallery document only when the caller still has
	 * the current revision. This is deliberately separate from lifecycle writes.
	 */
	public function updateDocument(Gallery $gallery, int $expectedRevision): Gallery {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('title', $qb->createNamedParameter($gallery->getTitle()))
			->set('settings', $qb->createNamedParameter($gallery->getSettings()))
			->set('updated_at', $qb->createNamedParameter($gallery->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('revision', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));

		if ($qb->executeStatement() !== 1) {
			throw new GalleryConflictException('The gallery changed in another session');
		}
		return $this->find($gallery->getId());
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

	/** @return list<Gallery> */
	public function findLifecycleCandidates(int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->neq('status', $qb->createNamedParameter('archived')))
			->orderBy('updated_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<Gallery> */
	public function findIndexCandidates(int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('source_type', $qb->createNamedParameter('folder')))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('archived')))
			->orderBy('updated_at', 'DESC')
			->setMaxResults(max(1, min(5000, $limit)));
		return $this->findEntities($qb);
	}

	/** @param list<int> $ids
	 * @return list<Gallery>
	 */
	public function findMany(array $ids, int $limit = 100): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
		if ($ids === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_slice($ids, 0, $limit), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
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
