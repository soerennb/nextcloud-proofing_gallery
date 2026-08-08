<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class MediaIndexScanRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function find(int $galleryId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_media_scans')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		$row = QueryResult::row($qb->executeQuery());
		return $row === false ? null : $row;
	}

	public function start(int $galleryId, string $generation, int $storageId, int $rootFileId, int $now): void {
		$this->deleteQueue($galleryId);
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('proofing_media_scans')
			->set('generation', $qb->createNamedParameter($generation))
			->set('status', $qb->createNamedParameter('running'))
			->set('root_storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT))
			->set('root_file_id', $qb->createNamedParameter($rootFileId, IQueryBuilder::PARAM_INT))
			->set('indexed_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('truncated', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('dirty', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('started_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($updated === 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('proofing_media_scans')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'generation' => $qb->createNamedParameter($generation),
				'status' => $qb->createNamedParameter('running'),
				'root_storage_id' => $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT),
				'root_file_id' => $qb->createNamedParameter($rootFileId, IQueryBuilder::PARAM_INT),
				'indexed_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'truncated' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'dirty' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
				'started_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
		$this->enqueue($galleryId, $generation, $rootFileId, '', 0);
	}

	public function markDirty(int $galleryId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_media_scans')
			->set('dirty', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return array<string, mixed>|null */
	public function nextFolder(int $galleryId, string $generation): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_media_scan_queue')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation)))
			->orderBy('id', 'ASC')->setMaxResults(1);
		$row = QueryResult::row($qb->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return list<array{file_id:int, parent:int, name:string, mime_type:string, size:int, mtime:int, etag:string}> */
	public function children(int $storageId, int $parentFileId, int $afterFileId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.fileid', 'f.parent', 'f.name', 'mt.mimetype', 'f.size', 'f.mtime', 'f.etag')
			->from('filecache', 'f')
			->innerJoin('f', 'mimetypes', 'mt', $qb->expr()->eq('mt.id', 'f.mimetype'))
			->where($qb->expr()->eq('f.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('f.parent', $qb->createNamedParameter($parentFileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('f.fileid', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
			->orderBy('f.fileid', 'ASC')->setMaxResults($limit);
		$rows = [];
		$result = $qb->executeQuery();
		while (($row = QueryResult::row($result)) !== false) {
			$rows[] = [
				'file_id' => (int)$row['fileid'], 'parent' => (int)$row['parent'], 'name' => (string)$row['name'],
				'mime_type' => (string)$row['mimetype'], 'size' => (int)$row['size'], 'mtime' => (int)$row['mtime'], 'etag' => (string)$row['etag'],
			];
		}
		$result->closeCursor();
		return $rows;
	}

	public function enqueue(int $galleryId, string $generation, int $parentFileId, string $path, int $depth): void {
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('proofing_media_scan_queue')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'generation' => $qb->createNamedParameter($generation),
				'parent_file_id' => $qb->createNamedParameter($parentFileId, IQueryBuilder::PARAM_INT),
				'relative_path' => $qb->createNamedParameter($path),
				'depth' => $qb->createNamedParameter($depth, IQueryBuilder::PARAM_INT),
				'after_file_id' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (UniqueConstraintViolationException) {
			// A folder is reached only once in a tree; duplicate cache events are harmless.
		}
	}

	public function advanceFolder(int $id, int $afterFileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_media_scan_queue')
			->set('after_file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function completeFolder(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_media_scan_queue')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function progress(int $galleryId, int $indexed, bool $truncated, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_media_scans')
			->set('indexed_count', $qb->createNamedParameter($indexed, IQueryBuilder::PARAM_INT))
			->set('truncated', $qb->createNamedParameter($truncated, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function finish(int $galleryId, bool $truncated, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_media_scans')
			->set('status', $qb->createNamedParameter($truncated ? 'limit_reached' : 'ready'))
			->set('truncated', $qb->createNamedParameter($truncated, IQueryBuilder::PARAM_BOOL))
			->set('dirty', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteQueue(int $galleryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_media_scan_queue')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
