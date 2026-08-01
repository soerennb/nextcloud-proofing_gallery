<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

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
		int $galleryId,
		int $limit,
		string|int|null $afterValue = null,
		?int $afterFileId = null,
		string $pathPrefix = '',
		string $search = '',
		string $sortBy = 'name',
		string $sortDirection = 'asc',
	): array {
		$sortColumn = match ($sortBy) {
			'modified' => 'mtime',
			'size' => 'size',
			default => 'sort_key',
		};
		$direction = $sortDirection === 'desc' ? 'DESC' : 'ASC';
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy($sortColumn, $direction)->addOrderBy('file_id', $direction)->setMaxResults($limit);
		if ($pathPrefix !== '') {
			$escaped = $this->db->escapeLikeParameter(trim($pathPrefix, '/'));
			$qb->andWhere($qb->expr()->like('relative_path', $qb->createNamedParameter($escaped . '/%')));
		}
		if ($search !== '') {
			$escaped = $this->db->escapeLikeParameter(mb_strtolower($search));
			$qb->andWhere($qb->expr()->like('sort_key', $qb->createNamedParameter('%' . $escaped . '%')));
		}
		if ($afterValue !== null && $afterFileId !== null) {
			$comparison = $direction === 'ASC' ? 'gt' : 'lt';
			$valueType = $sortColumn === 'sort_key' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT;
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->{$comparison}($sortColumn, $qb->createNamedParameter($afterValue, $valueType)),
				$qb->expr()->andX(
					$qb->expr()->eq($sortColumn, $qb->createNamedParameter($afterValue, $valueType)),
					$qb->expr()->{$comparison}('file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)),
				),
			));
		}
		return $this->findEntities($qb);
	}

	public function countFiltered(int $galleryId, string $pathPrefix = '', string $search = ''): int {
		$qb = $this->filteredQuery($galleryId, $pathPrefix, $search);
		$qb->select($qb->func()->count());
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<array{file_id: int, relative_path: string, mime_type: string}> */
	public function groupingRows(int $galleryId, string $pathPrefix = '', string $search = ''): array {
		$qb = $this->filteredQuery($galleryId, $pathPrefix, $search);
		$qb->select('file_id', 'relative_path', 'mime_type')->orderBy('sort_key', 'ASC');
		return array_map(static fn (array $row): array => [
			'file_id' => (int)$row['file_id'],
			'relative_path' => (string)$row['relative_path'],
			'mime_type' => (string)$row['mime_type'],
		], $qb->executeQuery()->fetchAllAssociative());
	}

	private function filteredQuery(int $galleryId, string $pathPrefix, string $search): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->from($this->tableName)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		if ($pathPrefix !== '') {
			$escaped = $this->db->escapeLikeParameter(trim($pathPrefix, '/'));
			$qb->andWhere($qb->expr()->like('relative_path', $qb->createNamedParameter($escaped . '/%')));
		}
		if ($search !== '') {
			$escaped = $this->db->escapeLikeParameter(mb_strtolower($search));
			$qb->andWhere($qb->expr()->like('sort_key', $qb->createNamedParameter('%' . $escaped . '%')));
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
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
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
