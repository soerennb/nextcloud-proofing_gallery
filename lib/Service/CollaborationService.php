<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Domain\FeedbackVisibility;
use OCA\ProofingGallery\Domain\GalleryMode;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class CollaborationService {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $clock,
		private FolderService $folders,
		private CollectionService $collections,
		private NotificationService $notifications,
	) {
	}

	/** @return array<string, mixed> */
	public function state(Gallery $gallery, ?Guest $guest, int $cursor): array {
		$settings = $this->settings($gallery);
		$visibleGuestId = $settings->feedbackVisibility === FeedbackVisibility::Private ? $guest?->getId() : null;
		$feedback = $this->rows('proofing_feedback', $gallery->getId(), $visibleGuestId);
		$comments = $this->rows('proofing_comments', $gallery->getId(), $visibleGuestId, 'created_at');
		$selections = $this->selectionRows($gallery->getId(), $visibleGuestId);
		if ($gallery->getSourceType() === 'collection') {
			$available = array_fill_keys(array_column($this->collections->availableItems($gallery), 'id'), true);
			$feedback = array_values(array_filter($feedback, static fn (array $row): bool => isset($available[(int)$row['file_id']])));
			$comments = array_values(array_filter($comments, static fn (array $row): bool => isset($available[(int)$row['file_id']])));
			foreach ($selections as &$selection) {
				$selection['fileIds'] = array_values(array_filter(
					$selection['fileIds'],
					static fn (int $fileId): bool => isset($available[$fileId]),
				));
			}
			unset($selection);
		}
		$events = $this->events($gallery->getId(), $visibleGuestId, $cursor);
		$nextCursor = $cursor;
		foreach ($events as $event) {
			$nextCursor = max($nextCursor, (int)$event['id']);
		}

		$likes = [];
		$colors = [];
		$colorStates = [];
		foreach ($feedback as $row) {
			$fileId = (int)$row['file_id'];
			if ($row['kind'] === 'like') {
				$likes[$fileId] ??= ['count' => 0, 'mine' => false];
				$likes[$fileId]['count']++;
				if ($guest !== null && (int)$row['guest_id'] === $guest->getId()) {
					$likes[$fileId]['mine'] = true;
				}
			}
			if ($row['kind'] === 'color' && $guest !== null && (int)$row['guest_id'] === $guest->getId()) {
				$colors[$fileId] = $row['value'];
			}
			if ($row['kind'] === 'color') {
				$colorStates[$fileId] ??= [];
				$colorStates[$fileId][$row['value']] = ($colorStates[$fileId][$row['value']] ?? 0) + 1;
			}
		}

		return [
			'policy' => [
				'enabled' => $settings->mode === GalleryMode::Collaboration,
				'visibility' => $settings->feedbackVisibility->value,
				'colorLabels' => $settings->colorLabels,
				'requiresSession' => $guest === null,
				'features' => [
					'likes' => $settings->review['likes'],
					'colors' => $settings->review['colors'],
					'comments' => $settings->review['comments'],
					'annotations' => $settings->review['annotations'],
					'selections' => $settings->review['selections'],
				],
			],
			'guest' => $guest,
			'likes' => $likes,
			'colors' => $colors,
			'colorStates' => $colorStates,
			'comments' => $this->presentComments($comments, $guest),
			'selections' => $this->presentSelections($selections, $guest),
			'events' => $events,
			'cursor' => $nextCursor,
		];
	}

	public function toggleLike(Gallery $gallery, Guest $guest, int $fileId): bool {
		$settings = $this->assertCollaboration($gallery, $fileId);
		if (!$settings->review['likes']) {
			throw new InvalidArgumentException('Likes are disabled');
		}
		$existing = $this->feedbackId($gallery->getId(), $guest->getId(), $fileId, 'like');
		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_feedback')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($existing, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$liked = false;
		} else {
			$this->insertFeedback($gallery, $guest, $fileId, 'like', '1');
			$liked = true;
		}
		$this->event($gallery, $guest, 'like.changed', ['fileId' => $fileId, 'liked' => $liked]);
		return $liked;
	}

	public function setColor(Gallery $gallery, Guest $guest, int $fileId, ?string $value): void {
		$settings = $this->assertCollaboration($gallery, $fileId);
		if (!$settings->review['colors']) {
			throw new InvalidArgumentException('Color states are disabled');
		}
		$enabledLabels = array_values(array_filter(
			$settings->colorLabels,
			static fn (string $_label, int $index): bool => $settings->review['colorEnabled'][$index],
			ARRAY_FILTER_USE_BOTH,
		));
		if ($value !== null && !in_array($value, $enabledLabels, true)) {
			throw new InvalidArgumentException('Unknown color workflow state');
		}
		$id = $this->feedbackId($gallery->getId(), $guest->getId(), $fileId, 'color');
		if ($value === null && $id !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_feedback')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		} elseif ($value !== null && $id === null) {
			$this->insertFeedback($gallery, $guest, $fileId, 'color', $value);
		} elseif ($value !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('proofing_feedback')
				->set('value', $qb->createNamedParameter($value))
				->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$this->event($gallery, $guest, 'color.changed', ['fileId' => $fileId, 'value' => $value]);
	}

	/** @param array<string, int>|null $annotation */
	public function addComment(Gallery $gallery, Guest $guest, int $fileId, string $body, ?array $annotation): int {
		$settings = $this->assertCollaboration($gallery, $fileId);
		if (!$settings->review['comments']) {
			throw new InvalidArgumentException('Comments are disabled');
		}
		if ($annotation !== null && !$settings->review['annotations']) {
			throw new InvalidArgumentException('Image annotations are disabled');
		}
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > 5000) {
			throw new InvalidArgumentException('Comment must contain between 1 and 5000 characters');
		}
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_comments')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter(null),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'parent_id' => $qb->createNamedParameter(null),
			'body' => $qb->createNamedParameter($body),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'edited_at' => $qb->createNamedParameter(null),
			'deleted_at' => $qb->createNamedParameter(null),
		])->executeStatement();
		$commentId = (int)$this->db->lastInsertId('proofing_comments');
		if ($annotation !== null) {
			$this->insertAnnotation($gallery->getId(), $fileId, $commentId, $annotation);
		}
		$this->event($gallery, $guest, 'comment.created', ['fileId' => $fileId, 'commentId' => $commentId]);
		return $commentId;
	}

	public function deleteComment(Gallery $gallery, Guest $guest, int $commentId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_comments')
			->set('deleted_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->set('body', $qb->createNamedParameter(''))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			throw new InvalidArgumentException('Comment cannot be deleted');
		}
		$this->event($gallery, $guest, 'comment.deleted', ['commentId' => $commentId]);
	}

	public function updateComment(Gallery $gallery, Guest $guest, int $commentId, string $body): void {
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > 5000) {
			throw new InvalidArgumentException('Comment must contain between 1 and 5000 characters');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_comments')
			->set('body', $qb->createNamedParameter($body))
			->set('edited_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		if ($qb->executeStatement() !== 1) {
			throw new InvalidArgumentException('Comment cannot be edited');
		}
		$this->event($gallery, $guest, 'comment.updated', ['commentId' => $commentId]);
	}

	/** @param list<int> $fileIds */
	public function saveSelection(Gallery $gallery, Guest $guest, string $name, string $message, array $fileIds): string {
		$this->assertCollaborationMode($gallery);
		if (!$this->settings($gallery)->review['selections']) {
			throw new InvalidArgumentException('Selections are disabled');
		}
		$name = trim($name);
		$message = trim($message);
		if ($name === '' || mb_strlen($name) > 120 || mb_strlen($message) > 2000) {
			throw new InvalidArgumentException('Invalid selection name or message');
		}
		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));
		if (count($fileIds) > 1000) {
			throw new InvalidArgumentException('Selection is too large');
		}
		foreach ($fileIds as $fileId) {
			$this->resolveMedia($gallery, $fileId);
		}
		$publicId = $this->uuid();
		$now = $this->clock->getTime();
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('proofing_selections')->values([
				'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
				'guest_id' => $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT),
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
			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
		$this->event($gallery, $guest, 'selection.created', ['selectionId' => $publicId, 'count' => count($fileIds)]);
		return $publicId;
	}

	/** @return array{content: string, filename: string, mimeType: string} */
	public function exportSelection(Gallery $gallery, ?Guest $guest, string $publicId, string $format): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_selections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)));
		$row = $qb->executeQuery()->fetchAssociative();
		if ($row === false
			|| ($guest !== null && $this->settings($gallery)->feedbackVisibility === FeedbackVisibility::Private
				&& (int)$row['guest_id'] !== $guest->getId())) {
			throw new InvalidArgumentException('Selection not found');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')->from('proofing_selection_items')
			->where($qb->expr()->eq('selection_id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
		$names = [];
		foreach ($qb->executeQuery()->fetchFirstColumn() as $fileId) {
			try {
				$names[] = $this->resolveMedia($gallery, (int)$fileId)->getName();
			} catch (\Throwable) {
				// Files removed after selection creation are intentionally omitted.
			}
		}
		$base = preg_replace('/[^a-z0-9._-]+/i', '-', (string)$row['name']) ?: 'selection';
		return match ($format) {
			'csv' => [
				'content' => "filename\r\n" . implode('', array_map(
					static fn (string $name): string => '"' . str_replace('"', '""', $name) . "\"\r\n",
					$names,
				)),
				'filename' => $base . '.csv',
				'mimeType' => 'text/csv',
			],
			'search' => [
				'content' => implode(' OR ', array_map(
					static fn (string $name): string => 'name:"' . str_replace('"', '\\"', $name) . '"',
					$names,
				)),
				'filename' => $base . '-search.txt',
				'mimeType' => 'text/plain',
			],
			'plain' => [
				'content' => implode("\n", $names) . "\n",
				'filename' => $base . '.txt',
				'mimeType' => 'text/plain',
			],
			default => throw new InvalidArgumentException('Unknown export format'),
		};
	}

	/** @return list<array<string, mixed>> */
	public function ownerSelections(Gallery $gallery): array {
		return array_reverse($this->presentSelections($this->selectionRows($gallery->getId(), null), null));
	}

	/** @return array{content: string, filename: string, mimeType: string} */
	public function exportOwnerSelection(Gallery $gallery, string $publicId, string $format): array {
		return $this->exportSelection($gallery, null, $publicId, $format);
	}

	public function updateOwnerSelection(Gallery $gallery, string $publicId, string $name, string $status): void {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120 || !in_array($status, ['open', 'completed'], true)) {
			throw new InvalidArgumentException('Invalid selection name or status');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_selections')
			->set('name', $qb->createNamedParameter($name))
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)));
		if ($qb->executeStatement() !== 1) throw new InvalidArgumentException('Selection not found');
	}

	public function deleteOwnerSelection(Gallery $gallery, string $publicId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('proofing_selections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)));
		$id = $qb->executeQuery()->fetchOne();
		if ($id === false) throw new InvalidArgumentException('Selection not found');
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_selection_items')->where($qb->expr()->eq('selection_id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_selections')->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	private function settings(Gallery $gallery): GallerySettings {
		return GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
	}

	private function assertCollaboration(Gallery $gallery, int $fileId): GallerySettings {
		$settings = $this->assertCollaborationMode($gallery);
		$this->resolveMedia($gallery, $fileId);
		return $settings;
	}

	private function resolveMedia(Gallery $gallery, int $fileId): \OCP\Files\File {
		try {
			return $gallery->getSourceType() === 'collection'
				? $this->collections->resolveMedia($gallery, $fileId)
				: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
		} catch (\Throwable) {
			throw new InvalidArgumentException('Media file is unavailable');
		}
	}

	private function assertCollaborationMode(Gallery $gallery): GallerySettings {
		$settings = $this->settings($gallery);
		if ($settings->mode !== GalleryMode::Collaboration) {
			throw new InvalidArgumentException('Collaboration is disabled');
		}
		return $settings;
	}

	private function feedbackId(int $galleryId, int $guestId, int $fileId, string $kind): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('proofing_feedback')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		$value = $qb->executeQuery()->fetchOne();
		return $value === false ? null : (int)$value;
	}

	private function insertFeedback(Gallery $gallery, Guest $guest, int $fileId, string $kind, string $value): void {
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_feedback')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter(null),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'kind' => $qb->createNamedParameter($kind),
			'value' => $qb->createNamedParameter($value),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
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

	/** @return list<array<string, mixed>> */
	private function rows(string $table, int $galleryId, ?int $guestId, string $order = 'updated_at'): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy($order, 'ASC');
		if ($guestId !== null) {
			$qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		}
		return $qb->executeQuery()->fetchAllAssociative();
	}

	/** @return list<array<string, mixed>> */
	private function selectionRows(int $galleryId, ?int $guestId): array {
		$rows = $this->rows('proofing_selections', $galleryId, $guestId);
		foreach ($rows as &$row) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id')->from('proofing_selection_items')
				->where($qb->expr()->eq('selection_id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$row['fileIds'] = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
		}
		return $rows;
	}

	/** @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function presentComments(array $rows, ?Guest $viewer): array {
		return array_map(function (array $row) use ($viewer): array {
			$qb = $this->db->getQueryBuilder();
			$qb->select('x', 'y', 'width', 'height')->from('proofing_annotations')
				->where($qb->expr()->eq('comment_id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			return [
				'id' => (int)$row['id'],
				'fileId' => (int)$row['file_id'],
				'body' => $row['body'],
				'createdAt' => (int)$row['created_at'],
				'editedAt' => $row['edited_at'] === null ? null : (int)$row['edited_at'],
				'deletedAt' => $row['deleted_at'] === null ? null : (int)$row['deleted_at'],
				'mine' => $viewer !== null && (int)$row['guest_id'] === $viewer->getId(),
				'author' => $this->guestName((int)$row['guest_id']),
				'annotations' => array_map(
					static fn (array $annotation): array => array_map('intval', $annotation),
					$qb->executeQuery()->fetchAllAssociative(),
				),
			];
		}, $rows);
	}

	/** @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function presentSelections(array $rows, ?Guest $viewer): array {
		return array_map(fn (array $row): array => [
			'id' => $row['public_id'],
			'name' => $row['name'],
			'message' => $row['message'],
			'status' => $row['status'],
			'fileIds' => $row['fileIds'],
			'updatedAt' => (int)$row['updated_at'],
			'mine' => $viewer !== null && (int)$row['guest_id'] === $viewer->getId(),
			'author' => $this->guestName((int)$row['guest_id']),
		], $rows);
	}

	private function guestName(int $guestId): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('display_name')->from('proofing_guests')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		$name = $qb->executeQuery()->fetchOne();
		return $name === false ? 'Deleted guest' : (string)$name;
	}

	/** @return list<array<string, mixed>> */
	private function events(int $galleryId, ?int $guestId, int $cursor): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_events')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter(max(0, $cursor), IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults(200);
		if ($guestId !== null) {
			$qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		}
		$rows = $qb->executeQuery()->fetchAllAssociative();
		return array_map(static fn (array $row): array => [
			'id' => (int)$row['id'],
			'type' => $row['event_type'],
			'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
			'createdAt' => (int)$row['created_at'],
		], $rows);
	}

	/** @param array<string, mixed> $payload */
	private function event(Gallery $gallery, Guest $guest, string $type, array $payload): void {
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_events')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guest->getId(), IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter(null),
			'event_type' => $qb->createNamedParameter($type),
			'payload' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$this->notifications->queue($gallery, (int)$this->db->lastInsertId('proofing_events'), $type, $now);
	}

	private function uuid(): string {
		$hex = bin2hex(random_bytes(16));
		return sprintf('%s-%s-4%s-%s%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), dechex((hexdec($hex[16]) & 3) | 8), substr($hex, 17, 3), substr($hex, 20, 12));
	}
}
