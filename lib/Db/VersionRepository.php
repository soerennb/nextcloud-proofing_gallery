<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class VersionRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string, mixed>> */
	public function list(int $galleryId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('version_id', 'filename', 'mime_type', 'size', 'created_by', 'created_at')
			->from('proofing_versions')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->executeQuery());
	}

	public function insert(int $galleryId, int $fileId, string $versionId, string $filename, string $mimeType, int $size, string $createdBy, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_versions')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'version_id' => $qb->createNamedParameter($versionId),
			'filename' => $qb->createNamedParameter($filename),
			'mime_type' => $qb->createNamedParameter($mimeType),
			'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			'created_by' => $qb->createNamedParameter($createdBy),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function expired(int $before, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('id', 'gallery_id', 'file_id', 'version_id')->from('proofing_versions')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults(max(1, min(1000, $limit)))->executeQuery());
	}

	/** @return list<array<string, mixed>> */
	public function excess(int $galleryId, int $fileId, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('id', 'gallery_id', 'file_id', 'version_id')->from('proofing_versions')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->setFirstResult($offset)->executeQuery());
	}

	public function exists(int $galleryId, int $fileId, string $versionId): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->select('id')->from('proofing_versions')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('version_id', $qb->createNamedParameter($versionId)))
			->executeQuery()->fetchOne() !== false;
	}

	/** @param list<int> $ids */
	public function delete(array $ids): int {
		if ($ids === []) return 0;
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('proofing_versions')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
