<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class GalleryMediaCountRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function find(int $galleryId): ?array {
		return $this->findMany([$galleryId])[$galleryId] ?? null;
	}

	/** @param list<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	public function findMany(array $ids): array {
		if ($ids === []) return [];
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('*')->from('proofing_media_counts')
			->where($qb->expr()->in('gallery_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery());
		$result = [];
		foreach ($rows as $row) $result[(int)$row['gallery_id']] = $row;
		return $result;
	}

	public function ensure(int $id, int $now): void {
		if ($this->find($id) !== null) return;
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('proofing_media_counts')->values(['gallery_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT), 'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)])->executeStatement();
		} catch (UniqueConstraintViolationException) {
		}
	}

	public function invalidate(int $id, int $now, bool $clear = false): void {
		$this->ensure($id, $now);
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_media_counts')->set('revision', $qb->createFunction('revision + 1'))
			->set('state', $qb->createNamedParameter('pending'))->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		if ($clear) foreach (['image_count', 'video_count', 'counted_at', 'witness_file_id'] as $column) $qb->set($column, $qb->createNamedParameter(null));
		$qb->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @param array<string, int|string|null> $values */
	public function update(int $id, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_media_counts');
		foreach ($values as $column => $value) $qb->set($column, $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		$qb->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** Conditional publication prevents a late invalidation being overwritten. */
	public function publish(int $id, string $generation, int $revision, int $images, int $videos, ?int $witness, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_media_counts')->set('image_count', $qb->createNamedParameter($images, IQueryBuilder::PARAM_INT))
			->set('video_count', $qb->createNamedParameter($videos, IQueryBuilder::PARAM_INT))->set('witness_file_id', $qb->createNamedParameter($witness, $witness === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('counted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))->set('state', $qb->createNamedParameter('ready'))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation)))->executeStatement() === 1;
	}

	public function clearQueue(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_count_queue')->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** Persist visited IDs, including completed folders, for cycle and alias protection. */
	public function enqueue(int $id, string $generation, int $fileId, string $path, bool $done = false): bool {
		// The gallery lock serializes writers. Check aliases before inserting so
		// PostgreSQL does not abort the scan transaction on a duplicate key.
		$existing = $this->db->getQueryBuilder();
		if ($existing->select('id')->from('proofing_count_queue')
			->where($existing->expr()->eq('gallery_id', $existing->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($existing->expr()->eq('generation', $existing->createNamedParameter($generation)))
			->andWhere($existing->expr()->eq('file_id', $existing->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne() !== false) return false;
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('proofing_count_queue')->values([
				'gallery_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT), 'generation' => $qb->createNamedParameter($generation),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT), 'relative_path' => $qb->createNamedParameter($path), 'done' => $qb->createNamedParameter($done, IQueryBuilder::PARAM_BOOL),
			])->executeStatement();
			return true;
		} catch (UniqueConstraintViolationException) {
			return false;
		}
	}

	/** @return array<string, mixed>|null */
	public function next(int $id, string $generation): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_count_queue')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation)))
			->andWhere($qb->expr()->eq('done', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'ASC')->setMaxResults(1)->executeQuery());
		return $row === false ? null : $row;
	}

	public function advance(int $queueId, int $after, bool $done): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_count_queue')->set('after_file_id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT))->set('done', $qb->createNamedParameter($done, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($queueId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @return array<string, int> */
	public function health(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('state', $qb->func()->count('*', 'total'))->from('proofing_media_counts')->groupBy('state')->executeQuery());
		$result = [];
		foreach ($rows as $row) $result[(string)$row['state']] = (int)$row['total'];
		$stalled = $this->db->getQueryBuilder();
		$result['stalled'] = (int)$stalled->select($stalled->func()->count('*'))->from('proofing_media_counts')
			->where($stalled->expr()->in('state', $stalled->createNamedParameter(['pending', 'updating', 'error'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($stalled->expr()->lt('updated_at', $stalled->createNamedParameter($now - 900, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
		return $result;
	}

	/** @return list<array<string, mixed>> */
	public function due(int $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('g.id', 'c.state')->from('proofing_galleries', 'g')
			->leftJoin('g', 'proofing_media_counts', 'c', $qb->expr()->eq('g.id', 'c.gallery_id'))
			->where($qb->expr()->orX($qb->expr()->isNull('c.gallery_id'),
				$qb->expr()->andX($qb->expr()->in('c.state', $qb->createNamedParameter(['pending', 'updating', 'error'], IQueryBuilder::PARAM_STR_ARRAY)), $qb->expr()->lt('c.updated_at', $qb->createNamedParameter($now - 900, IQueryBuilder::PARAM_INT))),
				$qb->expr()->lt('c.updated_at', $qb->createNamedParameter($now - 86400, IQueryBuilder::PARAM_INT))))
			->orderBy('c.updated_at', 'ASC')->addOrderBy('g.id', 'ASC')->setMaxResults($limit)->executeQuery());
	}
}
