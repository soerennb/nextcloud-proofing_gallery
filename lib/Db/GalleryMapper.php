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
			->set('lifecycle_revoke_at', $qb->createNamedParameter($gallery->getLifecycleRevokeAt(), IQueryBuilder::PARAM_INT))
			->set('lifecycle_archive_at', $qb->createNamedParameter($gallery->getLifecycleArchiveAt(), IQueryBuilder::PARAM_INT))
			->set('lifecycle_next_at', $qb->createNamedParameter($gallery->getLifecycleNextAt(), IQueryBuilder::PARAM_INT))
			->set('mode', $qb->createNamedParameter($gallery->getMode()))
			->set('title_sort', $qb->createNamedParameter($gallery->getTitleSort()))
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
			->addOrderBy('id', 'DESC')
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

	/**
	 * @param list<string> $groupIds
	 * @param array{value: int|string, id: int}|null $cursor
	 * @return array{items: list<Gallery>, total: int}
	 */
	public function findAccessiblePage(
		string $userUid,
		array $groupIds,
		bool $archived,
		string $search,
		?string $sourceType,
		?string $status,
		?string $mode,
		?string $purpose,
		bool $ownedOnly,
		string $sort,
		?array $cursor,
		int $limit,
	): array {
		$qb = $this->accessibleQuery($userUid, $groupIds, $archived, $search, $sourceType, $status, $mode, $purpose, $ownedOnly);
		$qb->selectDistinct('g.*');
		$field = match ($sort) {
			'title' => 'g.title_sort',
			'created' => 'g.created_at',
			default => 'g.updated_at',
		};
		$direction = $sort === 'title' ? 'ASC' : 'DESC';
		if ($cursor !== null) {
			$comparison = $direction === 'ASC' ? 'gt' : 'lt';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->{$comparison}($field, $qb->createNamedParameter($cursor['value'], $sort === 'title' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->eq($field, $qb->createNamedParameter($cursor['value'], $sort === 'title' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT)),
					$qb->expr()->{$comparison}('g.id', $qb->createNamedParameter($cursor['id'], IQueryBuilder::PARAM_INT)),
				),
			));
		}
		$qb->orderBy($field, $direction)->addOrderBy('g.id', $direction)->setMaxResults($limit);
		$items = $this->findEntities($qb);

		$count = $this->accessibleQuery($userUid, $groupIds, $archived, $search, $sourceType, $status, $mode, $purpose, $ownedOnly);
		$count->select($count->createFunction('COUNT(DISTINCT g.id)'));
		return ['items' => $items, 'total' => (int)$count->executeQuery()->fetchOne()];
	}

	/** @param list<string> $groupIds */
	private function accessibleQuery(
		string $userUid,
		array $groupIds,
		bool $archived,
		string $search,
		?string $sourceType,
		?string $status,
		?string $mode,
		?string $purpose,
		bool $ownedOnly,
	): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$userMembership = $qb->expr()->andX(
			$qb->expr()->eq('m.principal_type', $qb->createNamedParameter('user')),
			$qb->expr()->eq('m.user_uid', $qb->createNamedParameter($userUid)),
		);
		$membershipMatches = [$userMembership];
		if ($groupIds !== []) {
			$membershipMatches[] = $qb->expr()->andX(
				$qb->expr()->eq('m.principal_type', $qb->createNamedParameter('group')),
				$qb->expr()->in('m.user_uid', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		$qb->from($this->tableName, 'g')
			->leftJoin('g', 'proofing_managers', 'm', $qb->expr()->andX(
				$qb->expr()->eq('m.gallery_id', 'g.id'),
				$qb->expr()->orX(...$membershipMatches),
			));
		$owner = $qb->expr()->eq('g.owner_uid', $qb->createNamedParameter($userUid));
		$qb->where($ownedOnly ? $owner : $qb->expr()->orX($owner, $qb->expr()->isNotNull('m.id')));
		$archiveStatus = $qb->createNamedParameter('archived');
		$qb->andWhere($archived ? $qb->expr()->eq('g.status', $archiveStatus) : $qb->expr()->neq('g.status', $archiveStatus));
		if ($status !== null) $qb->andWhere($qb->expr()->eq('g.status', $qb->createNamedParameter($status)));
		if ($sourceType !== null) $qb->andWhere($qb->expr()->eq('g.source_type', $qb->createNamedParameter($sourceType)));
		if ($mode !== null) $qb->andWhere($qb->expr()->eq('g.mode', $qb->createNamedParameter($mode)));
		if ($purpose !== null) $qb->andWhere($qb->expr()->eq('g.purpose', $qb->createNamedParameter($purpose)));
		if ($search !== '') {
			$needle = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->iLike('g.title', $qb->createNamedParameter($needle)));
		}
		return $qb;
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
	public function findLifecycleCandidates(int $now, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->neq('status', $qb->createNamedParameter('archived')))
			->andWhere($qb->expr()->isNotNull('lifecycle_next_at'))
			->andWhere($qb->expr()->lte('lifecycle_next_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('lifecycle_next_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<Gallery> */
	public function findLifecycleProjectionBatch(int $afterId, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults(max(1, min(500, $limit)));
		return $this->findEntities($qb);
	}

	public function updateLifecycleProjection(Gallery $gallery): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('lifecycle_revoke_at', $qb->createNamedParameter($gallery->getLifecycleRevokeAt(), IQueryBuilder::PARAM_INT))
			->set('lifecycle_archive_at', $qb->createNamedParameter($gallery->getLifecycleArchiveAt(), IQueryBuilder::PARAM_INT))
			->set('lifecycle_next_at', $qb->createNamedParameter($gallery->getLifecycleNextAt(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function updateListProjection(Gallery $gallery): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('mode', $qb->createNamedParameter($gallery->getMode()))
			->set('title_sort', $qb->createNamedParameter($gallery->getTitleSort()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return list<Gallery> */
	public function findArchivedWithActiveLinks(int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('g.*')->from($this->tableName, 'g')
			->innerJoin('g', 'proofing_public_links', 'l', $qb->expr()->eq('l.gallery_id', 'g.id'))
			->where($qb->expr()->eq('g.status', $qb->createNamedParameter('archived')))
			->andWhere($qb->expr()->eq('l.status', $qb->createNamedParameter('active')))
			->orderBy('g.updated_at', 'ASC')->setMaxResults($limit);
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

	/** @return list<Gallery> */
	public function findContextCandidates(int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->neq('status', $qb->createNamedParameter('archived')))
			->orderBy('updated_at', 'DESC')
			->setMaxResults(max(1, min(5000, $limit)));
		return $this->findEntities($qb);
	}

	/** @return list<Gallery> */
	public function findContextCandidatesAfterId(int $afterId, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->neq('status', $qb->createNamedParameter('archived')))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults(max(1, min(500, $limit)));
		return $this->findEntities($qb);
	}

	/** @param list<int> $folderIds
	 * @return list<Gallery>
	 */
	public function findActiveFolderSources(array $folderIds): array {
		$folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), static fn (int $id): bool => $id > 0)));
		if ($folderIds === []) return [];
		$result = [];
		foreach (array_chunk($folderIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->tableName)
				->where($qb->expr()->in('folder_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter('folder')))
				->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('archived')));
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
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

	/** @return array{items: list<Gallery>, total: int} */
	public function findAdminPage(int $limit, int $offset, string $search = ''): array {
		$filter = function (IQueryBuilder $qb) use ($search): void {
			if ($search === '') return;
			$needle = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->where($qb->expr()->orX(
				$qb->expr()->iLike('title', $qb->createNamedParameter($needle)),
				$qb->expr()->iLike('owner_uid', $qb->createNamedParameter($needle)),
			));
		};
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)->orderBy('updated_at', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min(100, $limit)))->setFirstResult(max(0, $offset));
		$filter($qb);
		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count())->from($this->tableName);
		$filter($count);
		return ['items' => $this->findEntities($qb), 'total' => (int)$count->executeQuery()->fetchOne()];
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
