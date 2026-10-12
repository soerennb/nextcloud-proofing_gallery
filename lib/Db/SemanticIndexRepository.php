<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class SemanticIndexRepository {
	private const TABLE = 'proofing_semantic_idx';

	public function __construct(private IDBConnection $db) {
	}

	/** Freeze membership once; retries and batches address stable positions.
	 * @param iterable<int> $ids */
	public function snapshot(int $galleryId, string $generation, iterable $ids, int $limit): void {
		$this->db->beginTransaction();
		try {
			$this->deleteCandidates($galleryId);
			$position = 0;
			foreach ($ids as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('proofing_sem_candidates')->values([
					'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
					'generation' => $qb->createNamedParameter($generation),
					'position' => $qb->createNamedParameter($position++, IQueryBuilder::PARAM_INT),
					'file_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
				])->executeStatement();
				if ($position >= $limit) break;
			}
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
	}

	/** @return list<int> */
	public function candidates(int $galleryId, string $generation, int $offset, int $limit): array {
		$qb = $this->candidateQuery($galleryId, $generation);
		return array_map('intval', QueryResult::column($qb->select('file_id')->andWhere($qb->expr()->gte('position', $qb->createNamedParameter($offset, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')->setMaxResults($limit)->executeQuery()));
	}

	public function candidateCount(int $galleryId, string $generation): int {
		$qb = $this->candidateQuery($galleryId, $generation);
		return (int)$qb->select($qb->func()->count())->executeQuery()->fetchOne();
	}

	private function candidateQuery(int $galleryId, string $generation): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		return $qb->from('proofing_sem_candidates')->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation)));
	}

	public function deleteCandidates(int $galleryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_sem_candidates')->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @param list<float> $vector
	 * @param list<string> $concepts
	 */
	public function upsert(int $galleryId, int $fileId, string $etag, string $provider, string $model, string $generation, array $vector, array $concepts, int $now): void {
		$existing = $this->find($galleryId, $fileId, $generation);
		$values = ['source_etag' => $etag, 'provider' => $provider, 'model' => $model,
			'vector' => json_encode($vector, JSON_THROW_ON_ERROR), 'concepts' => json_encode($concepts, JSON_THROW_ON_ERROR), 'updated_at' => $now];
		$qb = $this->db->getQueryBuilder();
		if ($existing === null) {
			$qb->insert(self::TABLE)->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'generation' => $qb->createNamedParameter($generation),
				...array_map(fn (mixed $value) => $qb->createNamedParameter($value), $values),
			])->executeStatement();
			return;
		}
		$qb->update(self::TABLE);
		foreach ($values as $key => $value) $qb->set($key, $qb->createNamedParameter($value));
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @return array<string, mixed>|null */
	public function find(int $galleryId, int $fileId, string $generation): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation)));
		$row = QueryResult::row($qb->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return list<array<string, mixed>> */
	public function gallery(int $galleryId, string $provider, string $model, string $generation, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($model)))
			->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation)))->setMaxResults($limit);
		return QueryResult::rows($qb->executeQuery());
	}

	public function deleteGallery(int $galleryId): int {
		$this->deleteCandidates($galleryId);
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteOtherGenerations(int $galleryId, string $generation): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('generation', $qb->createNamedParameter($generation)))
			->executeStatement();
	}

	public function deleteAll(): int {
		$this->db->getQueryBuilder()->delete('proofing_sem_candidates')->executeStatement();
		return $this->db->getQueryBuilder()->delete(self::TABLE)->executeStatement();
	}

	/** @return array{items: int, galleries: int} */
	public function health(): array {
		$items = $this->db->getQueryBuilder();
		$items->select($items->func()->count())->from(self::TABLE);
		$galleries = $this->db->getQueryBuilder();
		$galleries->selectDistinct('gallery_id')->from(self::TABLE);
		return ['items' => (int)$items->executeQuery()->fetchOne(), 'galleries' => count(QueryResult::rows($galleries->executeQuery()))];
	}
}
