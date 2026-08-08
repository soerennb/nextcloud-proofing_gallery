<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Exception\CollectionConflictException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class CollectionRepository {
	public function __construct(private IDBConnection $db) {
	}

	public function initialize(int $galleryId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_collections')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @param list<array{sourceGalleryId: int, fileId: int}> $items */
	public function replace(int $collectionId, int $revision, array $items, int $now): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$updated = $qb->update('proofing_collections')
				->set('revision', $qb->createNamedParameter($revision + 1, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			if ($updated !== 1) throw new CollectionConflictException('The collection changed in another session');
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_collection_items')
				->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			foreach ($items as $position => $item) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('proofing_collection_items')->values([
					'collection_id' => $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT),
					'source_gallery_id' => $qb->createNamedParameter($item['sourceGalleryId'], IQueryBuilder::PARAM_INT),
					'file_id' => $qb->createNamedParameter($item['fileId'], IQueryBuilder::PARAM_INT),
					'position' => $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	/** @return list<array<string, mixed>> */
	public function items(int $collectionId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_collection_items')
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')->executeQuery());
	}

	/** @param list<int> $collectionIds
	 * @return array<int, int>
	 */
	public function counts(array $collectionIds): array {
		if ($collectionIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('collection_id', $qb->func()->count('*', 'item_count'))
			->from('proofing_collection_items')
			->where($qb->expr()->in('collection_id', $qb->createNamedParameter($collectionIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('collection_id')->executeQuery());
		$result = [];
		foreach ($rows as $row) $result[(int)$row['collection_id']] = (int)$row['item_count'];
		return $result;
	}

	public function sourceGalleryId(int $collectionId, int $fileId): ?int {
		$qb = $this->db->getQueryBuilder();
		$value = $qb->select('source_gallery_id')->from('proofing_collection_items')
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne();
		return $value === false ? null : (int)$value;
	}

	public function revision(int $galleryId): ?int {
		$qb = $this->db->getQueryBuilder();
		$value = $qb->select('revision')->from('proofing_collections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne();
		return $value === false ? null : (int)$value;
	}
}
