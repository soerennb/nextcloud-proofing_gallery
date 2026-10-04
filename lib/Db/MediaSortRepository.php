<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Updates the private sort projection without depending on file or metadata services. */
final class MediaSortRepository {
	public function __construct(private IDBConnection $db) {
	}

	public function revision(int $galleryId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sort_revision')->from('proofing_media_scans')->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return (string)($qb->executeQuery()->fetchOne() ?: '');
	}

	public function touch(int $galleryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_media_scans')->set('sort_revision', $qb->createNamedParameter(bin2hex(random_bytes(16))))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function updateCapture(int $fileId, string $etag, ?int $capturedAt, string $state): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('gallery_id', 'captured_at', 'capture_state')->from('proofing_media_index')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('etag', $qb->createNamedParameter($etag)));
		$galleryIds = array_column(array_filter(QueryResult::rows($qb->executeQuery()), static function (array $row) use ($capturedAt, $state): bool {
			$current = $row['captured_at'] === null ? null : (int)$row['captured_at'];
			return $current !== $capturedAt || $row['capture_state'] !== $state;
		}), 'gallery_id');
		if ($galleryIds === []) return;
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('proofing_media_index')
				->set('captured_at', $qb->createNamedParameter($capturedAt, $capturedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
				->set('capture_missing', $qb->createNamedParameter((int)($capturedAt === null), IQueryBuilder::PARAM_INT))
				->set('capture_state', $qb->createNamedParameter($state))
				->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('etag', $qb->createNamedParameter($etag)))->executeStatement();
			foreach ($galleryIds as $id) $this->touch((int)$id);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
	}

	/** @return list<array{file_id: int, etag: string}> */
	public function pending(int $galleryId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'etag')->from('proofing_media_index')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('capture_state', $qb->createNamedParameter('pending')))
			->orderBy('file_id', 'ASC')->setMaxResults(max(1, min(200, $limit)));
		return array_map(static fn (array $row): array => ['file_id' => (int)$row['file_id'], 'etag' => (string)$row['etag']], QueryResult::rows($qb->executeQuery()));
	}

	/** @param list<int> $folderIds
	 * @return list<int>
	 */
	public function sidecarFileIds(array $folderIds, string $stem): array {
		if ($folderIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('file_id')->addSelect('name')->from('proofing_media_index')
			->where($qb->expr()->in('parent_file_id', $qb->createNamedParameter($folderIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->like('name', $qb->createNamedParameter($this->db->escapeLikeParameter($stem) . '.%')));
		return array_values(array_map(static fn (array $row): int => (int)$row['file_id'], array_filter(QueryResult::rows($qb->executeQuery()), static fn (array $row): bool => pathinfo((string)$row['name'], PATHINFO_FILENAME) === $stem)));
	}

	/** @return array{pending: int, ready: int, failed: int} */
	public function health(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('capture_state')->addSelect($qb->func()->count('*', 'count'))->from('proofing_media_index')->groupBy('capture_state');
		$counts = ['pending' => 0, 'ready' => 0, 'failed' => 0];
		foreach (QueryResult::rows($qb->executeQuery()) as $row) {
			if (isset($counts[(string)$row['capture_state']])) $counts[(string)$row['capture_state']] = (int)$row['count'];
		}
		return $counts;
	}
}
