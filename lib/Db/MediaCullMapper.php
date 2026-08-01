<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<MediaCull> */
final class MediaCullMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_media_cull', MediaCull::class);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function findForOwnerFile(string $ownerUid, int $fileId): MediaCull {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @param list<int> $fileIds
	 * @return list<MediaCull>
	 */
	public function findMany(string $ownerUid, array $fileIds): array {
		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));
		if ($fileIds === []) return [];
		$result = [];
		// Keep the query portable below SQLite's conservative bind-parameter
		// ceiling and avoid one unbounded IN clause for 25k-media summaries.
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->tableName)
				->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}

	public function updateRevision(MediaCull $cull, int $expectedRevision): MediaCull {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('rating', $qb->createNamedParameter($cull->getRating(), IQueryBuilder::PARAM_INT))
			->set('color', $qb->createNamedParameter($cull->getColor()))
			->set('pick_state', $qb->createNamedParameter($cull->getPickState()))
			->set('source', $qb->createNamedParameter($cull->getSource()))
			->set('source_etag', $qb->createNamedParameter($cull->getSourceEtag()))
			->set('sidecar_etag', $qb->createNamedParameter($cull->getSidecarEtag()))
			->set('updated_at', $qb->createNamedParameter($cull->getUpdatedAt(), IQueryBuilder::PARAM_INT))
			->set('revision', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($cull->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) throw new MetadataConflictException('The culling state changed in another session');
		return $this->findForOwnerFile($cull->getOwnerUid(), $cull->getFileId());
	}
}
