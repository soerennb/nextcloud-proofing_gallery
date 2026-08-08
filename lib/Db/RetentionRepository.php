<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class RetentionRepository {
	public function __construct(private IDBConnection $db) {
	}

	public function record(int $galleryId, int $folderId, string $tagId, string $action, string $actor, string $outcome, ?string $error, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_retention_log')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'folder_id' => $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT),
			'tag_id' => $qb->createNamedParameter($tagId), 'action' => $qb->createNamedParameter($action),
			'actor_uid' => $qb->createNamedParameter($actor), 'outcome' => $qb->createNamedParameter($outcome),
			'error_code' => $qb->createNamedParameter($error), 'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return array<string, mixed>|null */
	public function latest(int $galleryId): ?array {
		return $this->latestMatching($galleryId, null);
	}

	/** @return array<string, mixed>|null */
	public function latestSuccessful(int $galleryId): ?array {
		return $this->latestMatching($galleryId, 'success');
	}

	/** @return array<string, mixed>|null */
	private function latestMatching(int $galleryId, ?string $outcome): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_retention_log')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')->setMaxResults(1);
		if ($outcome !== null) $qb->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter($outcome)));
		$row = QueryResult::row($qb->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return array{assigned:int,failed:int} */
	public function health(): array {
		$assigned = $this->latestOutcomeCount('assign', 'success');
		return ['assigned' => $assigned, 'failed' => $this->latestOutcomeCount('', 'failed')];
	}

	private function latestOutcomeCount(string $action, string $outcome): int {
		$qb = $this->db->getQueryBuilder();
		$sub = $this->db->getQueryBuilder();
		$sub->select($sub->func()->max('id'))->from('proofing_retention_log')->groupBy('gallery_id');
		$qb->select($qb->func()->count())->from('proofing_retention_log')
			->where($qb->expr()->in('id', $qb->createFunction($sub->getSQL())))
			->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter($outcome)));
		if ($action !== '') $qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
