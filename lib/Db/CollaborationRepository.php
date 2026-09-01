<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use InvalidArgumentException;
use OCA\ProofingGallery\Domain\CollaborationReadScope;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class CollaborationRepository {
	private const COLLABORATION_EVENT_TYPES = [
		'like.changed', 'color.changed', 'comment.created', 'comment.updated', 'comment.deleted',
		'rating.changed', 'selection.created', 'selection.updated', 'selection.deleted',
	];
	private const ROW_LIMITS = [
		'proofing_feedback' => 200000,
		'proofing_comments' => 10000,
		'proofing_selections' => 2000,
	];
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @return array{
	 *     feedback: list<array<string, mixed>>,
	 *     comments: list<array<string, mixed>>,
	 *     selections: list<array<string, mixed>>,
	 *     events: list<array<string, mixed>>
	 * }
	 * @param list<int> $visibleFileIds
	 */
	public function state(int $galleryId, CollaborationReadScope $scope, int $cursor, array $visibleFileIds = [], bool $allFiles = false): array {
		if ($scope->isEmpty()) {
			return [
				'feedback' => [], 'comments' => [], 'selections' => [], 'events' => [],
				'unchanged' => false, 'delta' => false,
			];
		}
		$guestId = $scope->guestId();
		$events = $this->events($galleryId, $guestId, $cursor);
		if ($cursor > 0 && $events === []) {
			return ['feedback' => [], 'comments' => [], 'selections' => [], 'events' => [], 'unchanged' => true];
		}
		$delta = $cursor > 0;
		$eventFileIds = [];
		$commentIds = [];
		$selectionIds = [];
		if ($delta) {
			foreach ($events as $event) {
				$payload = json_decode((string)$event['payload'], true);
				if (!is_array($payload)) continue;
				if ((int)($payload['fileId'] ?? 0) > 0) $eventFileIds[] = (int)$payload['fileId'];
				if ((int)($payload['commentId'] ?? 0) > 0) $commentIds[] = (int)$payload['commentId'];
				if (is_string($payload['selectionId'] ?? null)) $selectionIds[] = $payload['selectionId'];
			}
		}
		$fileIds = array_values(array_unique($delta ? $eventFileIds : array_map('intval', $visibleFileIds)));
		$feedback = $allFiles && !$delta
			? $this->rows('proofing_feedback', $galleryId, $guestId, 'updated_at')
			: $this->rowsForFiles('proofing_feedback', $galleryId, $guestId, $fileIds, 'updated_at');
		$comments = $allFiles && !$delta
			? $this->rows('proofing_comments', $galleryId, $guestId, 'created_at')
			: $this->commentsForDelta($galleryId, $guestId, $fileIds, $commentIds);
		$selections = $delta
			? $this->selectionsByPublicIds($galleryId, $guestId, $selectionIds)
			: $this->selectionPage($galleryId, $guestId, null, 50);
		$this->decorateSelections($selections);
		$annotations = $this->annotations(array_map(static fn (array $row): int => (int)$row['id'], $comments));
		$names = $this->guestNames(array_values(array_unique(array_map(
			static fn (array $row): int => (int)$row['guest_id'],
			$comments,
		))));
		foreach ($comments as &$comment) {
			$commentId = (int)$comment['id'];
			$comment['author'] = $names[(int)$comment['guest_id']] ?? 'Deleted guest';
			$comment['annotations'] = $annotations[$commentId] ?? [];
		}
		unset($comment);
		return [
			'feedback' => $feedback,
			'comments' => $comments,
			'selections' => $selections,
			'events' => $events,
			'unchanged' => false,
			'delta' => $delta,
		];
	}

	/** @return list<array<string, mixed>> */
	public function selections(int $galleryId, ?int $guestId): array {
		$selections = $this->rows('proofing_selections', $galleryId, $guestId, 'updated_at');
		$this->decorateSelections($selections);
		return $selections;
	}

	/** @return list<array<string, mixed>> */
	public function selectionPage(int $galleryId, ?int $guestId, ?int $beforeId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_selections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')->setMaxResults(max(1, min(101, $limit)));
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		if ($beforeId !== null) $qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		return QueryResult::rows($qb->executeQuery());
	}

	public function selectionCount(int $galleryId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from('proofing_selections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @param list<array<string, mixed>> $selections */
	public function decorateSelections(array &$selections): void {
		$items = $this->selectionItems(array_map(static fn (array $row): int => (int)$row['id'], $selections));
		$names = $this->guestNames(array_values(array_unique(array_map(
			static fn (array $row): int => (int)$row['guest_id'],
			$selections,
		))));
		foreach ($selections as &$selection) {
			$selection['author'] = $names[(int)$selection['guest_id']] ?? 'Deleted guest';
			$selection['fileIds'] = $items[(int)$selection['id']] ?? [];
		}
		unset($selection);
	}

	/** @param list<int> $fileIds
	 * @param list<int> $commentIds
	 * @return list<array<string, mixed>> */
	private function commentsForDelta(int $galleryId, ?int $guestId, array $fileIds, array $commentIds): array {
		if ($fileIds === [] && $commentIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_comments')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$fileIds === [] ? $qb->expr()->eq('id', $qb->createNamedParameter(-1, IQueryBuilder::PARAM_INT)) : $qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)),
				$commentIds === [] ? $qb->expr()->eq('id', $qb->createNamedParameter(-1, IQueryBuilder::PARAM_INT)) : $qb->expr()->in('id', $qb->createNamedParameter($commentIds, IQueryBuilder::PARAM_INT_ARRAY)),
			))->orderBy('id', 'ASC')->setMaxResults(1000);
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		return QueryResult::rows($qb->executeQuery());
	}

	/** @param list<string> $publicIds
	 * @return list<array<string, mixed>> */
	private function selectionsByPublicIds(int $galleryId, ?int $guestId, array $publicIds): array {
		if ($publicIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_selections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('public_id', $qb->createNamedParameter($publicIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC')->setMaxResults(200);
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		return QueryResult::rows($qb->executeQuery());
	}

	public function feedbackId(int $galleryId, int $guestId, int $fileId, string $kind): ?int {
		$qb = $this->db->getQueryBuilder();
		$value = $qb->select('id')->from('proofing_feedback')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->executeQuery()->fetchOne();
		return $value === false ? null : (int)$value;
	}

	public function deleteFeedback(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_feedback')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function insertFeedback(int $galleryId, int $guestId, int $fileId, string $kind, string $value, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_feedback')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter(null),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'kind' => $qb->createNamedParameter($kind),
			'value' => $qb->createNamedParameter($value),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	public function hasAtLeastRows(string $table, int $galleryId, int $threshold, ?int $guestId = null): bool {
		if (!array_key_exists($table, self::ROW_LIMITS)) throw new InvalidArgumentException('Unsupported collaboration table');
		if ($threshold < 1) return true;
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($table)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		$qb->orderBy('id', 'ASC')->setFirstResult($threshold - 1)->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	public function updateFeedback(int $id, string $value, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_feedback')
			->set('value', $qb->createNamedParameter($value))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @param array<string, int>|null $annotation */
	public function insertComment(int $galleryId, int $guestId, int $fileId, string $body, ?array $annotation, int $now): int {
		$ownsTransaction = !$this->db->inTransaction();
		if ($ownsTransaction) $this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('proofing_comments')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'guest_id' => $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT),
				'actor_uid' => $qb->createNamedParameter(null),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'parent_id' => $qb->createNamedParameter(null),
				'body' => $qb->createNamedParameter($body),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'edited_at' => $qb->createNamedParameter(null),
				'deleted_at' => $qb->createNamedParameter(null),
			])->executeStatement();
			$commentId = (int)$this->db->lastInsertId('proofing_comments');
			if ($annotation !== null) $this->insertAnnotation($galleryId, $fileId, $commentId, $annotation);
			if ($ownsTransaction) $this->db->commit();
			return $commentId;
		} catch (\Throwable $exception) {
			if ($ownsTransaction) $this->db->rollBack();
			throw $exception;
		}
	}

	public function deleteComment(int $galleryId, int $guestId, int $commentId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_comments')
			->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('body', $qb->createNamedParameter(''))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->executeStatement() === 1;
	}

	public function ownedCommentFileId(int $galleryId, int $guestId, int $commentId): ?int {
		$qb = $this->db->getQueryBuilder();
		$value = $qb->select('file_id')->from('proofing_comments')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))->executeQuery()->fetchOne();
		return $value === false ? null : (int)$value;
	}

	public function updateComment(int $galleryId, int $guestId, int $commentId, string $body, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_comments')
			->set('body', $qb->createNamedParameter($body))
			->set('edited_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'))->executeStatement() === 1;
	}

	/** @param list<int> $fileIds */
	public function insertSelection(int $galleryId, int $guestId, string $publicId, string $name, string $message, array $fileIds, int $now): void {
		$ownsTransaction = !$this->db->inTransaction();
		if ($ownsTransaction) $this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('proofing_selections')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'guest_id' => $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT),
				'actor_uid' => $qb->createNamedParameter(null),
				'public_id' => $qb->createNamedParameter($publicId),
				'name' => $qb->createNamedParameter($name),
				'message' => $qb->createNamedParameter($message),
				'status' => $qb->createNamedParameter('open'),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			$selectionId = (int)$this->db->lastInsertId('proofing_selections');
			foreach ($fileIds as $fileId) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('proofing_selection_items')->values([
					'selection_id' => $qb->createNamedParameter($selectionId, IQueryBuilder::PARAM_INT),
					'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			if ($ownsTransaction) $this->db->commit();
		} catch (\Throwable $exception) {
			if ($ownsTransaction) $this->db->rollBack();
			throw $exception;
		}
	}

	public function markResponseReceived(int $galleryId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_galleries')
			->set('workflow_state', $qb->createNamedParameter('response_received'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('revision', $qb->createFunction('revision + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('workflow_state', $qb->createNamedParameter('completed')))
			->executeStatement();
	}

	/** @return array<string, mixed>|null */
	public function selection(int $galleryId, string $publicId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_selections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return list<int> */
	public function selectionFileIds(int $selectionId): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('intval', QueryResult::column($qb->select('file_id')->from('proofing_selection_items')
			->where($qb->expr()->eq('selection_id', $qb->createNamedParameter($selectionId, IQueryBuilder::PARAM_INT)))
			->executeQuery()));
	}

	/** @param list<int> $fileIds
	 * @return array<int, list<string>>
	 */
	public function commentsByFileIds(int $galleryId, array $fileIds): array {
		$result = [];
		foreach (array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$rows = QueryResult::rows($qb->select('file_id', 'body')->from('proofing_comments')
				->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNull('deleted_at'))->orderBy('created_at', 'ASC')->executeQuery());
			foreach ($rows as $comment) $result[(int)$comment['file_id']][] = trim((string)$comment['body']);
		}
		return $result;
	}

	public function updateSelection(int $galleryId, string $publicId, string $name, string $status, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_selections')
			->set('name', $qb->createNamedParameter($name))
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
			->executeStatement() === 1;
	}

	public function deleteSelection(int $galleryId, string $publicId): bool {
		$row = $this->selection($galleryId, $publicId);
		if ($row === null) return false;
		$ownsTransaction = !$this->db->inTransaction();
		if ($ownsTransaction) $this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_selection_items')->where($qb->expr()->eq('selection_id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_selections')->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
			if ($ownsTransaction) $this->db->commit();
			return true;
		} catch (\Throwable $exception) {
			if ($ownsTransaction) $this->db->rollBack();
			throw $exception;
		}
	}

	/** @param array<string, mixed> $payload */
	public function insertEvent(int $galleryId, int $guestId, string $type, array $payload, int $now): int {
		return $this->insertActorEvent($galleryId, $guestId, null, $type, $payload, $now);
	}

	/** @param array<string, mixed> $payload */
	public function insertOwnerEvent(int $galleryId, int $guestId, string $actorUid, string $type, array $payload, int $now): int {
		return $this->insertActorEvent($galleryId, $guestId, $actorUid, $type, $payload, $now);
	}

	/** @param array<string, mixed> $payload */
	private function insertActorEvent(int $galleryId, int $guestId, ?string $actorUid, string $type, array $payload, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_events')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter($actorUid),
			'event_type' => $qb->createNamedParameter($type),
			'payload' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return (int)$this->db->lastInsertId('proofing_events');
	}

	/** @return list<array<string, mixed>> */
	private function rows(string $table, int $galleryId, ?int $guestId, string $order): array {
		$limit = self::ROW_LIMITS[$table] ?? throw new InvalidArgumentException('Unsupported collaboration table');
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy($order, 'DESC')->setMaxResults(min(5000, $limit));
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		return array_reverse(QueryResult::rows($qb->executeQuery()));
	}

	/** @param list<int> $fileIds
	 * @return list<array<string, mixed>> */
	private function rowsForFiles(string $table, int $galleryId, ?int $guestId, array $fileIds, string $order): array {
		if ($fileIds === []) return [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy($order, 'ASC')->setMaxResults(5000);
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		return QueryResult::rows($qb->executeQuery());
	}

	/** @param list<int> $selectionIds
	 * @return array<int, list<int>>
	 */
	private function selectionItems(array $selectionIds): array {
		$result = [];
		foreach (array_chunk($selectionIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$rows = QueryResult::rows($qb->select('selection_id', 'file_id')->from('proofing_selection_items')
				->where($qb->expr()->in('selection_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC')->executeQuery());
			foreach ($rows as $row) $result[(int)$row['selection_id']][] = (int)$row['file_id'];
		}
		return $result;
	}

	/** @param list<int> $commentIds
	 * @return array<int, list<array{x: int, y: int, width: int, height: int}>>
	 */
	private function annotations(array $commentIds): array {
		$result = [];
		foreach (array_chunk($commentIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$rows = QueryResult::rows($qb->select('comment_id', 'x', 'y', 'width', 'height')->from('proofing_annotations')
				->where($qb->expr()->in('comment_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC')->executeQuery());
			foreach ($rows as $row) {
				$result[(int)$row['comment_id']][] = [
					'x' => (int)$row['x'], 'y' => (int)$row['y'],
					'width' => (int)$row['width'], 'height' => (int)$row['height'],
				];
			}
		}
		return $result;
	}

	/** @param list<int> $guestIds
	 * @return array<int, string>
	 */
	private function guestNames(array $guestIds): array {
		$result = [];
		foreach (array_chunk($guestIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$rows = QueryResult::rows($qb->select('id', 'display_name')->from('proofing_guests')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeQuery());
			foreach ($rows as $row) $result[(int)$row['id']] = (string)$row['display_name'];
		}
		return $result;
	}

	/** @return list<array<string, mixed>> */
	private function events(int $galleryId, ?int $guestId, int $cursor): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_events')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter(max(0, $cursor), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('event_type', $qb->createNamedParameter(self::COLLABORATION_EVENT_TYPES, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC')->setMaxResults(200);
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		return QueryResult::rows($qb->executeQuery());
	}

	/** @param array<string, int> $annotation */
	private function insertAnnotation(int $galleryId, int $fileId, int $commentId, array $annotation): void {
		foreach (['x', 'y', 'width', 'height'] as $key) {
			if (!isset($annotation[$key]) || !is_int($annotation[$key]) || $annotation[$key] < 0 || $annotation[$key] > 10000) {
				throw new InvalidArgumentException('Annotation coordinates must be normalized integers');
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_annotations')->values([
			'comment_id' => $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT),
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'x' => $qb->createNamedParameter($annotation['x'], IQueryBuilder::PARAM_INT),
			'y' => $qb->createNamedParameter($annotation['y'], IQueryBuilder::PARAM_INT),
			'width' => $qb->createNamedParameter($annotation['width'], IQueryBuilder::PARAM_INT),
			'height' => $qb->createNamedParameter($annotation['height'], IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}
}
