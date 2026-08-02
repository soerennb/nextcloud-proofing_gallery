<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class SemanticIndexRepository {
	private const TABLE = 'proofing_semantic_idx';

	public function __construct(private IDBConnection $db) {
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
