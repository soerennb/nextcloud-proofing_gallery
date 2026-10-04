<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\ProofingGallery\Domain\MediaSort;
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
		bool $before = false,
		int $offset = 0,
	): array {
		$naturalDirection = $query->sortDirection === 'desc' ? 'DESC' : 'ASC';
		$direction = $before ? ($naturalDirection === 'ASC' ? 'DESC' : 'ASC') : $naturalDirection;
		$comparison = $direction === 'ASC' ? 'gt' : 'lt';
		$sortColumn = $this->sortColumn($query);
		$qb = $this->filteredQuery($query);
		$this->order($qb, $query, $direction, $before);
		$qb->select('m.*')->setMaxResults($query->limit);
		if ($afterFileId === null && !$before) $qb->setFirstResult(max(0, $offset));
		if ($afterFileId !== null) {
			$fileCondition = $qb->expr()->{$comparison}('m.file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT));
			if ($query->sortBy === 'capturedAt' && $afterValue === null) {
				$valueCondition = $fileCondition;
			} else {
				$valueType = $query->sortBy === 'name' ? IQueryBuilder::PARAM_LOB : IQueryBuilder::PARAM_INT;
				$valueCondition = $qb->expr()->orX(
					$qb->expr()->{$comparison}($sortColumn, $qb->createNamedParameter($afterValue, $valueType)),
					$qb->expr()->andX($qb->expr()->eq($sortColumn, $qb->createNamedParameter($afterValue, $valueType)), $fileCondition),
				);
			}
			if ($query->sortBy === 'capturedAt') {
				$bucket = (int)($afterValue === null);
				$bucketComparison = $before ? 'lt' : 'gt';
				$valueCondition = $qb->expr()->orX(
					$qb->expr()->{$bucketComparison}('m.capture_missing', $qb->createNamedParameter($bucket, IQueryBuilder::PARAM_INT)),
					$qb->expr()->andX($qb->expr()->eq('m.capture_missing', $qb->createNamedParameter($bucket, IQueryBuilder::PARAM_INT)), $valueCondition),
				);
			}
			$qb->andWhere($valueCondition);
		}
		$entities = $this->findEntities($qb);
		return $before ? array_reverse($entities) : $entities;
	}

	public function positionOf(MediaIndexQuery $query, int $fileId): ?int {
		$qb = $this->filteredQuery($query);
		$qb->select('m.file_id');
		$this->order($qb, $query, $query->sortDirection === 'desc' ? 'DESC' : 'ASC');
		$position = 0;
		$result = $qb->executeQuery();
		while (($value = $result->fetchOne()) !== false) {
			if ((int)$value === $fileId) {
				$result->closeCursor();
				return $position;
			}
			$position++;
		}
		$result->closeCursor();
		return null;
	}

	private function sortColumn(MediaIndexQuery $query): string {
		return match ($query->sortBy) {
			'modified' => 'm.mtime', 'size' => 'm.size', 'capturedAt' => 'm.captured_at', default => 'm.natural_name',
		};
	}

	private function order(IQueryBuilder $qb, MediaIndexQuery $query, string $direction, bool $before = false): void {
		if ($query->sortBy === 'capturedAt') $qb->orderBy('m.capture_missing', $before ? 'DESC' : 'ASC');
		$qb->addOrderBy($this->sortColumn($query), $direction)->addOrderBy('m.file_id', $direction);
	}

	public function countFiltered(MediaIndexQuery $query): int {
		$qb = $this->filteredQuery($query);
		$qb->select($qb->func()->count('m.id'));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<array{file_id: int, relative_path: string, mime_type: string}> */
	public function groupingRows(MediaIndexQuery $query, int $afterFileId = 0, int $limit = 1000): array {
		$qb = $this->filteredQuery($query);
		$qb->select('m.file_id', 'm.relative_path', 'm.mime_type')
			->andWhere($qb->expr()->gt('m.file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
			->orderBy('m.file_id', 'ASC')->setMaxResults(max(1, min(2000, $limit)));
		return array_map(static fn (array $row): array => [
			'file_id' => (int)$row['file_id'],
			'relative_path' => (string)$row['relative_path'],
			'mime_type' => (string)$row['mime_type'],
		], QueryResult::rows($qb->executeQuery()));
	}

	/** @return array<string, int> */
	public function mimeCounts(MediaIndexQuery $query): array {
		$qb = $this->filteredQuery($query);
		$qb->select('m.mime_type')->addSelect($qb->func()->count('m.id', 'media_count'))->groupBy('m.mime_type');
		$counts = [];
		$result = $qb->executeQuery();
		while (($row = QueryResult::row($result)) !== false) $counts[(string)$row['mime_type']] = (int)$row['media_count'];
		$result->closeCursor();
		return $counts;
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

	/** @param array{name: string, mimeType: string, size: int, mtime: int, etag: string, capturedAt?: ?int, captureState?: string} $file */
	public function upsert(
		int $galleryId,
		int $fileId,
		int $parentId,
		string $relativePath,
		int $depth,
		string $generation,
		int $now,
		array $file,
	): void {
		$sortKey = mb_strtolower(mb_substr($relativePath, 0, 512));
		$naturalName = MediaSort::nameKey($file['name']);
		$capturedAt = $file['capturedAt'] ?? null;
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update($this->tableName)
			->set('parent_file_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT))
			->set('relative_path', $qb->createNamedParameter($relativePath))
			->set('sort_key', $qb->createNamedParameter($sortKey))
			->set('natural_name', $qb->createNamedParameter($naturalName, IQueryBuilder::PARAM_LOB))
			->set('captured_at', $qb->createNamedParameter($capturedAt, $capturedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('capture_missing', $qb->createNamedParameter((int)($capturedAt === null), IQueryBuilder::PARAM_INT))
			->set('capture_state', $qb->createNamedParameter($file['captureState'] ?? 'pending'))
			->set('name', $qb->createNamedParameter($file['name']))
			->set('mime_type', $qb->createNamedParameter($file['mimeType']))
			->set('size', $qb->createNamedParameter($file['size'], IQueryBuilder::PARAM_INT))
			->set('mtime', $qb->createNamedParameter($file['mtime'], IQueryBuilder::PARAM_INT))
			->set('etag', $qb->createNamedParameter($file['etag']))
			->set('depth', $qb->createNamedParameter($depth, IQueryBuilder::PARAM_INT))
			->set('scan_generation', $qb->createNamedParameter($generation))
			->set('seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($updated === 1) return;
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert($this->tableName)->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'parent_file_id' => $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT),
				'relative_path' => $qb->createNamedParameter($relativePath),
				'sort_key' => $qb->createNamedParameter($sortKey),
				'natural_name' => $qb->createNamedParameter($naturalName, IQueryBuilder::PARAM_LOB),
				'captured_at' => $qb->createNamedParameter($capturedAt, $capturedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'capture_missing' => $qb->createNamedParameter((int)($capturedAt === null), IQueryBuilder::PARAM_INT),
				'capture_state' => $qb->createNamedParameter($file['captureState'] ?? 'pending'),
				'name' => $qb->createNamedParameter($file['name']),
				'mime_type' => $qb->createNamedParameter($file['mimeType']),
				'size' => $qb->createNamedParameter($file['size'], IQueryBuilder::PARAM_INT),
				'mtime' => $qb->createNamedParameter($file['mtime'], IQueryBuilder::PARAM_INT),
				'etag' => $qb->createNamedParameter($file['etag']),
				'depth' => $qb->createNamedParameter($depth, IQueryBuilder::PARAM_INT),
				'scan_generation' => $qb->createNamedParameter($generation),
				'seen_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (UniqueConstraintViolationException) {
			$this->upsert($galleryId, $fileId, $parentId, $relativePath, $depth, $generation, $now, $file);
		}
	}
}
