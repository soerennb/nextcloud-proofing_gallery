<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Grouped, index-friendly reads of the client work that waits for the owner.
 * Every method takes one page of gallery ids so the dashboard stays one query
 * per signal instead of one per gallery.
 */
class GalleryAttentionRepository {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @param list<int> $galleryIds
	 * @return array<int, array{count: int, latest: int}>
	 */
	public function openSelections(array $galleryIds): array {
		return $this->grouped('proofing_selections', $galleryIds, 'updated_at', fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open'))));
	}

	/**
	 * @param list<int> $galleryIds
	 * @return array<int, array{count: int, latest: int}>
	 */
	public function uploadsAwaitingReview(array $galleryIds): array {
		return $this->grouped('proofing_uploads', $galleryIds, 'created_at', fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('awaiting_review'))));
	}

	/**
	 * @param list<int> $galleryIds
	 * @return array<int, array{count: int, latest: int}>
	 */
	public function submittedReviews(array $galleryIds): array {
		return $this->grouped('proofing_review_rounds', $galleryIds, 'submitted_at', fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('submitted'))));
	}

	/**
	 * @param list<int> $galleryIds
	 * @return array<int, array{count: int, latest: int}>
	 */
	public function overdueReviews(array $galleryIds, string $today): array {
		return $this->grouped('proofing_review_rounds', $galleryIds, 'updated_at', fn (IQueryBuilder $qb) => $qb
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('awaiting_feedback')))
			->andWhere($qb->expr()->isNotNull('due_date'))
			->andWhere($qb->expr()->neq('due_date', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->lt('due_date', $qb->createNamedParameter($today))));
	}

	/**
	 * Guest comments of the last days; owner replies are not client activity.
	 * @param list<int> $galleryIds
	 * @return array<int, array{count: int, latest: int}>
	 */
	public function recentGuestComments(array $galleryIds, int $since): array {
		return $this->grouped('proofing_comments', $galleryIds, 'created_at', fn (IQueryBuilder $qb) => $qb
			->andWhere($qb->expr()->isNotNull('guest_id'))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT))));
	}

	/**
	 * @param list<int> $galleryIds
	 * @return array<int, array{count: int, latest: int}>
	 */
	public function guestFeedback(array $galleryIds): array {
		return $this->grouped('proofing_feedback', $galleryIds, 'updated_at', fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->isNotNull('guest_id')));
	}

	/**
	 * Active client links whose Nextcloud share expires inside the window.
	 * @param list<int> $galleryIds
	 * @return array<int, array{count: int, latest: int}>
	 */
	public function expiringLinks(array $galleryIds, \DateTimeImmutable $from, \DateTimeImmutable $until): array {
		if ($galleryIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.gallery_id')
			->selectAlias($qb->func()->count('l.id'), 'item_count')
			->selectAlias($qb->func()->min('s.expiration'), 'latest')
			->from('proofing_public_links', 'l')
			->innerJoin('l', 'share', 's', $qb->expr()->eq('s.id', 'l.core_share_id'))
			->where($qb->expr()->in('l.gallery_id', $qb->createNamedParameter($galleryIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('l.status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->isNotNull('s.expiration'))
			->andWhere($qb->expr()->gte('s.expiration', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)))
			->andWhere($qb->expr()->lte('s.expiration', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)))
			->groupBy('l.gallery_id');
		$result = [];
		foreach (QueryResult::rows($qb->executeQuery()) as $row) {
			$latest = is_string($row['latest'] ?? null) ? strtotime($row['latest']) : false;
			$result[(int)$row['gallery_id']] = ['count' => (int)$row['item_count'], 'latest' => $latest === false ? 0 : $latest];
		}
		return $result;
	}

	/**
	 * @param list<int> $galleryIds
	 * @param callable(IQueryBuilder): IQueryBuilder $filter
	 * @return array<int, array{count: int, latest: int}>
	 */
	private function grouped(string $table, array $galleryIds, string $timeColumn, callable $filter): array {
		if ($galleryIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('gallery_id')
			->selectAlias($qb->func()->count('id'), 'item_count')
			->selectAlias($qb->func()->max($timeColumn), 'latest')
			->from($table)
			->where($qb->expr()->in('gallery_id', $qb->createNamedParameter($galleryIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$filter($qb)->groupBy('gallery_id');
		$result = [];
		foreach (QueryResult::rows($qb->executeQuery()) as $row) {
			$result[(int)$row['gallery_id']] = ['count' => (int)$row['item_count'], 'latest' => (int)($row['latest'] ?? 0)];
		}
		return $result;
	}
}
