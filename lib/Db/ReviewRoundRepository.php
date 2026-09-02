<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ReviewRoundRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function current(int $publicLinkId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('r.*', 'g.display_name AS submitted_by')
			->from('proofing_review_rounds', 'r')
			->leftJoin('r', 'proofing_guests', 'g', $qb->expr()->eq('g.id', 'r.submitted_by_guest_id'))
			->where($qb->expr()->eq('r.public_link_id', $qb->createNamedParameter($publicLinkId, IQueryBuilder::PARAM_INT)))
			->orderBy('r.round_number', 'DESC')->setMaxResults(1)->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return list<array<string, mixed>> */
	public function history(int $publicLinkId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('r.*', 'g.display_name AS submitted_by')
			->from('proofing_review_rounds', 'r')
			->leftJoin('r', 'proofing_guests', 'g', $qb->expr()->eq('g.id', 'r.submitted_by_guest_id'))
			->where($qb->expr()->eq('r.public_link_id', $qb->createNamedParameter($publicLinkId, IQueryBuilder::PARAM_INT)))
			->orderBy('r.round_number', 'DESC')->executeQuery());
	}

	public function create(int $galleryId, int $publicLinkId, int $number, ?string $dueDate, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_review_rounds')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'public_link_id' => $qb->createNamedParameter($publicLinkId, IQueryBuilder::PARAM_INT),
			'round_number' => $qb->createNamedParameter($number, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter('awaiting_feedback'),
			'due_date' => $qb->createNamedParameter($dueDate),
			'submitted_by_guest_id' => $qb->createNamedParameter(null),
			'submitted_at' => $qb->createNamedParameter(null),
			'decided_at' => $qb->createNamedParameter(null),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	public function updateDueDate(int $id, ?string $dueDate, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_review_rounds')
			->set('due_date', $qb->createNamedParameter($dueDate))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function submit(int $id, int $guestId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_review_rounds')
			->set('status', $qb->createNamedParameter('submitted'))
			->set('submitted_by_guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT))
			->set('submitted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('awaiting_feedback')))
			->executeStatement() === 1;
	}

	public function decide(int $id, string $from, string $to, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_review_rounds')
			->set('status', $qb->createNamedParameter($to))
			->set('decided_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($from)))
			->executeStatement() === 1;
	}

	public function reopen(int $id, string $from, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_review_rounds')
			->set('status', $qb->createNamedParameter('awaiting_feedback'))
			->set('submitted_by_guest_id', $qb->createNamedParameter(null))
			->set('submitted_at', $qb->createNamedParameter(null))
			->set('decided_at', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($from)))
			->executeStatement() === 1;
	}

	/** @return array{open: int, submitted: int, approved: int, overdue: int} */
	public function health(string $today): array {
		$counts = ['open' => 0, 'submitted' => 0, 'approved' => 0, 'overdue' => 0];
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('r.status', $qb->func()->count('*', 'count'))
			->from('proofing_review_rounds', 'r')
			->leftJoin('r', 'proofing_review_rounds', 'newer', $qb->expr()->andX(
				$qb->expr()->eq('newer.public_link_id', 'r.public_link_id'),
				$qb->expr()->gt('newer.round_number', 'r.round_number'),
			))
			->innerJoin('r', 'proofing_public_links', 'l', $qb->expr()->eq('l.id', 'r.public_link_id'))
			->where($qb->expr()->eq('l.status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->eq('l.review_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('newer.id'))
			->groupBy('r.status')->executeQuery());
		foreach ($rows as $row) {
			$status = (string)$row['status'];
			if ($status === 'awaiting_feedback') $counts['open'] += (int)$row['count'];
			if ($status === 'submitted') $counts['submitted'] += (int)$row['count'];
			if ($status === 'approved') $counts['approved'] += (int)$row['count'];
		}
		$overdue = $this->db->getQueryBuilder();
		$overdue->select($overdue->func()->count())->from('proofing_review_rounds', 'r')
			->leftJoin('r', 'proofing_review_rounds', 'newer', $overdue->expr()->andX(
				$overdue->expr()->eq('newer.public_link_id', 'r.public_link_id'),
				$overdue->expr()->gt('newer.round_number', 'r.round_number'),
			))
			->innerJoin('r', 'proofing_public_links', 'l', $overdue->expr()->eq('l.id', 'r.public_link_id'))
			->where($overdue->expr()->eq('r.status', $overdue->createNamedParameter('awaiting_feedback')))
			->andWhere($overdue->expr()->lt('r.due_date', $overdue->createNamedParameter($today)))
			->andWhere($overdue->expr()->isNull('newer.id'))
			->andWhere($overdue->expr()->eq('l.status', $overdue->createNamedParameter('active')));
		$counts['overdue'] = (int)$overdue->executeQuery()->fetchOne();
		return $counts;
	}
}
