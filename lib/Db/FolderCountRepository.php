<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * One row per folder. Direct counts are rebuilt whenever a folder is visited; subtree totals stay
 * valid for as long as the folder etag matches sub_etag, because Nextcloud propagates descendant
 * changes to the folder etag.
 */
final class FolderCountRepository {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @param list<int> $folderIds
	 * @return array<int, array<string, mixed>>
	 */
	public function findMany(int $galleryId, array $folderIds): array {
		if ($folderIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('*')->from('proofing_count_folders')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('folder_id', $qb->createNamedParameter(array_values($folderIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery());
		$result = [];
		foreach ($rows as $row) $result[(int)$row['folder_id']] = $row;
		return $result;
	}

	/** @return array<int, array<string, mixed>> Every row touched by one generation, keyed by folder ID. */
	public function generation(int $galleryId, string $generation): array {
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('*')->from('proofing_count_folders')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation)))
			->executeQuery());
		$result = [];
		foreach ($rows as $row) $result[(int)$row['folder_id']] = $row;
		return $result;
	}

	/** A visited folder drops its stored subtree until the walk completes, so a partial walk cannot be reused. */
	public function visit(int $galleryId, int $folderId, int $parentId, string $generation, string $etag, bool $cacheable): void {
		$this->upsert($galleryId, $folderId, [
			'parent_id' => $parentId, 'generation' => $generation, 'etag' => $etag, 'cacheable' => $cacheable, 'hit' => false,
			'direct_images' => 0, 'direct_videos' => 0, 'direct_witness' => null, 'sub_etag' => null,
		]);
	}

	/** A folder whose stored subtree still matches is carried into this generation without being walked. */
	public function markHit(int $galleryId, int $folderId, int $parentId, string $generation): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_count_folders')
			->set('parent_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT))
			->set('generation', $qb->createNamedParameter($generation))
			->set('hit', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function addDirect(int $galleryId, int $folderId, int $images, int $videos, ?int $witness): void {
		if ($images === 0 && $videos === 0 && $witness === null) return;
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_count_folders')
			->set('direct_images', $qb->createFunction('direct_images + ' . $images))
			->set('direct_videos', $qb->createFunction('direct_videos + ' . $videos))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($witness === null) return;
		$first = $this->db->getQueryBuilder();
		$first->update('proofing_count_folders')
			->set('direct_witness', $first->createNamedParameter($witness, IQueryBuilder::PARAM_INT))
			->where($first->expr()->eq('gallery_id', $first->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($first->expr()->eq('folder_id', $first->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($first->expr()->isNull('direct_witness'))
			->executeStatement();
	}

	/** Called when a walk completes: the subtree totals become valid for the folder's current etag. */
	public function storeSubtotals(int $galleryId, int $folderId, string $etag, int $images, int $videos, ?int $witness): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_count_folders')
			->set('sub_etag', $qb->createNamedParameter($etag))
			->set('sub_images', $qb->createNamedParameter($images, IQueryBuilder::PARAM_INT))
			->set('sub_videos', $qb->createNamedParameter($videos, IQueryBuilder::PARAM_INT))
			->set('sub_witness', $qb->createNamedParameter($witness, $witness === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cacheable', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeStatement();
	}

	/** @param array<string, mixed> $values */
	private function upsert(int $galleryId, int $folderId, array $values): void {
		$existing = $this->findMany($galleryId, [$folderId]);
		$qb = $this->db->getQueryBuilder();
		if (isset($existing[$folderId])) {
			$qb->update('proofing_count_folders');
			foreach ($values as $column => $value) $qb->set($column, $this->param($qb, $value));
			$qb->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return;
		}
		$qb->insert('proofing_count_folders')->values(['gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT), 'folder_id' => $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)]);
		foreach ($values as $column => $value) $qb->setValue($column, $this->param($qb, $value));
		$qb->executeStatement();
	}

	private function param(IQueryBuilder $qb, mixed $value): mixed {
		return match (true) {
			$value === null => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			is_bool($value) => $qb->createNamedParameter($value, IQueryBuilder::PARAM_BOOL),
			is_int($value) => $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT),
			default => $qb->createNamedParameter((string)$value),
		};
	}
}
