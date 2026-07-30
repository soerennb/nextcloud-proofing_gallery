<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Manager> */
final class ManagerMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_managers', Manager::class);
	}

	/** @return list<Manager> */
	public function findByGallery(int $galleryId): array {
		$qb = $this->galleryQuery($galleryId);
		$qb->orderBy('principal_type')->addOrderBy('user_uid');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findPrincipal(int $galleryId, string $type, string $principalId): Manager {
		$qb = $this->galleryQuery($galleryId);
		$qb->andWhere($qb->expr()->eq('principal_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($principalId)));
		return $this->findEntity($qb);
	}

	/** @param list<string> $groupIds
	 * @return list<Manager>
	 */
	public function findForUser(string $userId, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$userMatch = $qb->expr()->andX(
			$qb->expr()->eq('principal_type', $qb->createNamedParameter('user')),
			$qb->expr()->eq('user_uid', $qb->createNamedParameter($userId)),
		);
		$matches = [$userMatch];
		if ($groupIds !== []) {
			$matches[] = $qb->expr()->andX(
				$qb->expr()->eq('principal_type', $qb->createNamedParameter('group')),
				$qb->expr()->in('user_uid', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		$qb->select('*')->from($this->tableName)->where($qb->expr()->orX(...$matches));
		return $this->findEntities($qb);
	}

	private function galleryQuery(int $galleryId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return $qb;
	}
}
