<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<GuestRating> */
final class GuestRatingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_guest_ratings', GuestRating::class);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findGuestFile(int $galleryId, int $guestId, int $fileId): GuestRating {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<GuestRating> */
	public function findForGuest(int $galleryId, int $guestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->orderBy('file_id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<GuestRating> */
	public function findForGallery(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('file_id', 'ASC')->addOrderBy('guest_id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $fileIds
	 * @return list<GuestRating>
	 */
	public function findForGalleryFiles(int $galleryId, array $fileIds): array {
		$fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0)));
		if ($fileIds === []) return [];
		$result = [];
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->tableName)
				->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('file_id', 'ASC')->addOrderBy('guest_id', 'ASC');
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}
}
