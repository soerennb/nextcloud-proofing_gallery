<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Per-file client feedback counts for the owner's media grid. */
class MediaFeedbackRepository {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @param list<int> $fileIds
	 * @return array<int, array{likes: int, selections: int, comments: int}>
	 */
	public function counts(int $galleryId, array $fileIds): array {
		if ($fileIds === []) return [];
		$result = [];
		foreach ($fileIds as $fileId) $result[$fileId] = ['likes' => 0, 'selections' => 0, 'comments' => 0];

		$likes = $this->db->getQueryBuilder();
		$likes->select('file_id')->selectAlias($likes->func()->count('id'), 'item_count')
			->from('proofing_feedback')
			->where($likes->expr()->eq('gallery_id', $likes->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($likes->expr()->eq('kind', $likes->createNamedParameter('like')))
			->andWhere($likes->expr()->in('file_id', $likes->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('file_id');
		foreach (QueryResult::rows($likes->executeQuery()) as $row) $result[(int)$row['file_id']]['likes'] = (int)$row['item_count'];

		$selections = $this->db->getQueryBuilder();
		$selections->select('i.file_id')->selectAlias($selections->func()->count('i.id'), 'item_count')
			->from('proofing_selection_items', 'i')
			->innerJoin('i', 'proofing_selections', 's', $selections->expr()->eq('s.id', 'i.selection_id'))
			->where($selections->expr()->eq('s.gallery_id', $selections->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($selections->expr()->in('i.file_id', $selections->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('i.file_id');
		foreach (QueryResult::rows($selections->executeQuery()) as $row) $result[(int)$row['file_id']]['selections'] = (int)$row['item_count'];

		$comments = $this->db->getQueryBuilder();
		$comments->select('file_id')->selectAlias($comments->func()->count('id'), 'item_count')
			->from('proofing_comments')
			->where($comments->expr()->eq('gallery_id', $comments->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($comments->expr()->isNull('deleted_at'))
			->andWhere($comments->expr()->in('file_id', $comments->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('file_id');
		foreach (QueryResult::rows($comments->executeQuery()) as $row) $result[(int)$row['file_id']]['comments'] = (int)$row['item_count'];

		return $result;
	}
}
