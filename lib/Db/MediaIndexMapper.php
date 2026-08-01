<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Dto\MediaIndexQuery;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<MediaIndex> */
final class MediaIndexMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'proofing_media_index', MediaIndex::class);
	}

	/** @return list<MediaIndex> */
	public function page(
		MediaIndexQuery $query,
		string|int|null $afterValue = null,
		?int $afterFileId = null,
	): array {
		$sortColumn = match ($query->sortBy) {
			'modified' => 'm.mtime',
			'size' => 'm.size',
			default => 'm.sort_key',
		};
		$direction = $query->sortDirection === 'desc' ? 'DESC' : 'ASC';
		$qb = $this->filteredQuery($query);
		$qb->select('m.*')->orderBy($sortColumn, $direction)->addOrderBy('m.file_id', $direction)->setMaxResults($query->limit);
		if ($afterValue !== null && $afterFileId !== null) {
			$comparison = $direction === 'ASC' ? 'gt' : 'lt';
			$valueType = $sortColumn === 'm.sort_key' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT;
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->{$comparison}($sortColumn, $qb->createNamedParameter($afterValue, $valueType)),
				$qb->expr()->andX(
					$qb->expr()->eq($sortColumn, $qb->createNamedParameter($afterValue, $valueType)),
					$qb->expr()->{$comparison}('m.file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)),
				),
			));
		}
		return $this->findEntities($qb);
	}

	public function countFiltered(MediaIndexQuery $query): int {
		$qb = $this->filteredQuery($query);
		$qb->select($qb->func()->count('m.id'));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<array{file_id: int, relative_path: string, mime_type: string}> */
	public function groupingRows(MediaIndexQuery $query): array {
		$qb = $this->filteredQuery($query);
		$qb->select('m.file_id', 'm.relative_path', 'm.mime_type')->orderBy('m.sort_key', 'ASC');
		return array_map(static fn (array $row): array => [
			'file_id' => (int)$row['file_id'],
			'relative_path' => (string)$row['relative_path'],
			'mime_type' => (string)$row['mime_type'],
		], QueryResult::rows($qb->executeQuery()));
	}

	private function filteredQuery(MediaIndexQuery $query): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->from($this->tableName, 'm')
			->where($qb->expr()->eq('m.gallery_id', $qb->createNamedParameter($query->galleryId, IQueryBuilder::PARAM_INT)));
		if ($query->minOwnerRating > 0) {
			$qb->innerJoin('m', 'proofing_media_cull', 'c', $qb->expr()->andX(
				$qb->expr()->eq('c.file_id', 'm.file_id'),
				$qb->expr()->eq('c.owner_uid', $qb->createNamedParameter($query->ownerUid)),
				$qb->expr()->gte('c.rating', $qb->createNamedParameter($query->minOwnerRating, IQueryBuilder::PARAM_INT)),
			));
		}
		if ($query->pathPrefix !== '') {
			$escaped = $this->db->escapeLikeParameter($query->pathPrefix);
			$qb->andWhere($qb->expr()->like('m.relative_path', $qb->createNamedParameter($escaped . '/%')));
		}
		if ($query->search !== '') {
			$escaped = $this->db->escapeLikeParameter($query->search);
			$qb->andWhere($qb->expr()->like('m.sort_key', $qb->createNamedParameter('%' . $escaped . '%')));
		}
		return $qb;
	}

	public function countGallery(int $galleryId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function lastSeenAt(int $galleryId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('seen_at'))->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		$value = $qb->executeQuery()->fetchOne();
		return $value === false || $value === null ? null : (int)$value;
	}

	/** @return list<int> */
	public function fileIds(int $galleryId, int $limit, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC')->addOrderBy('file_id', 'ASC')
			->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit));
		return array_map('intval', QueryResult::column($qb->executeQuery()));
	}

	public function deleteOtherGenerations(int $galleryId, string $generation): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('scan_generation', $qb->createNamedParameter($generation)));
		return $qb->executeStatement();
	}

	public function deleteGallery(int $galleryId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
