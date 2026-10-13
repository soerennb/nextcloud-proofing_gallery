<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;

/** Explicit operator-selected orphan IDs only; never deletes files. */
final class OrphanMetadataCleanupService {
	public function __construct(private IDBConnection $db, private IFilesMetadataManager $metadata) {
	}

	/** @param list<int> $fileIds
	 * @return array{storageId:int, requested:int, unique:int, existing:int, eligible:int, cleaned:int, missing:int, mode:string}
	 */
	public function run(int $storageId, array $fileIds, bool $execute = false): array {
		if ($storageId < 1 || count($fileIds) > 100000 || !array_is_list($fileIds)) throw new \InvalidArgumentException('Use a positive storage ID and at most 100000 file IDs');
		foreach ($fileIds as $id) if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('File IDs must be positive integers');
		$qb = $this->db->getQueryBuilder();
		if (QueryResult::value($qb->select('numeric_id')->from('storages')->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))->executeQuery()) === false) {
			throw new \InvalidArgumentException('Storage does not exist');
		}
		$ids = array_values(array_unique($fileIds));
		$summary = ['storageId' => $storageId, 'requested' => count($fileIds), 'unique' => count($ids), 'existing' => 0, 'eligible' => 0, 'cleaned' => 0, 'missing' => 0, 'mode' => $execute ? 'execute' : 'dry-run'];
		foreach (array_chunk($ids, 500) as $batch) {
			$live = $this->idsIn('filecache', 'fileid', $batch, null);
			$summary['existing'] += count($live);
			$orphans = array_values(array_diff($batch, $live));
			$eligible = $this->metadataIds($orphans, $storageId);
			$summary['eligible'] += count($eligible);
			$summary['missing'] += count($orphans) - count($eligible);
			if (!$execute || $eligible === []) continue;
			// Recheck immediately before each bounded public API call.
			$eligible = array_values(array_diff($eligible, $this->idsIn('filecache', 'fileid', $eligible, null)));
			$this->db->beginTransaction();
			try {
				if (method_exists($this->metadata, 'deleteMetadataForFiles')) $this->metadata->deleteMetadataForFiles($storageId, $eligible);
				else foreach ($eligible as $id) $this->metadata->deleteMetadata($id);
				// Core metadata APIs can log database failures instead of throwing.
				if ($this->metadataIds($eligible, $storageId) !== []) throw new \RuntimeException('Metadata cleanup incomplete; inspect Nextcloud logs and resume with the same ID list');
				$this->db->commit();
			} catch (\Throwable $error) {
				$this->db->rollBack();
				throw $error;
			}
			$summary['cleaned'] += count($eligible);
		}
		return $summary;
	}

	/**
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function metadataIds(array $ids, int $storageId): array {
		return array_values(array_unique([...$this->idsIn('files_metadata', 'file_id', $ids, $storageId), ...$this->idsIn('files_metadata_index', 'file_id', $ids, null)]));
	}

	/**
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function idsIn(string $table, string $column, array $ids, ?int $storageId): array {
		if ($ids === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct($column)->from($table)->where($qb->expr()->in($column, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		if ($storageId !== null && method_exists($qb, 'hintShardKey')) $qb->hintShardKey('storage', $storageId);
		elseif (method_exists($qb, 'runAcrossAllShards')) $qb->runAcrossAllShards();
		return array_map('intval', QueryResult::column($qb->executeQuery()));
	}
}
