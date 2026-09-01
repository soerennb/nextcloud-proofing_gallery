<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\CollaborationRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Db\GuestRating;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Domain\CollaborationReadScope;
use OCA\ProofingGallery\Domain\FeedbackVisibility;
use OCA\ProofingGallery\Domain\GalleryMode;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

final class CollaborationService {
	private const MAX_FEEDBACK_PER_GALLERY = 200000;
	private const MAX_COMMENTS_PER_GALLERY = 10000;
	private const MAX_COMMENTS_PER_GUEST = 1000;
	private const MAX_SELECTIONS_PER_GALLERY = 2000;
	private const MAX_SELECTIONS_PER_GUEST = 100;
	/** @var list<array{gallery: Gallery, type: string, createdAt: int, recipients: list<string>, nativeStateIds: list<int>}> */
	private array $stagedActivities = [];
	public function __construct(
		private CollaborationRepository $repository,
		private IDBConnection $db,
		private ITimeFactory $clock,
		private FolderService $folders,
		private CollectionService $collections,
		private NotificationService $notifications,
		private CapabilityPolicyService $capabilities,
		private CullingService $culling,
		private GuestRatingService $guestRatings,
		private CsvEncoder $csv,
	) {
	}

	/** @param list<int> $visibleFileIds
	 * @return array<string, mixed> */
	public function publicState(Gallery $gallery, ?Guest $guest, int $cursor, array $visibleFileIds = []): array {
		$settings = $this->settings($gallery);
		$scope = $settings->review->visibility === FeedbackVisibility::Collaborative
			? CollaborationReadScope::all()
			: ($guest === null ? CollaborationReadScope::none() : CollaborationReadScope::guest($guest->getId()));
		return $this->state($gallery, $guest, $scope, $cursor, $visibleFileIds, false);
	}

	/** @param list<int> $visibleFileIds
	 * @return array<string, mixed> */
	public function ownerState(Gallery $gallery, array $visibleFileIds = []): array {
		return $this->state($gallery, null, CollaborationReadScope::all(), 0, $visibleFileIds, $visibleFileIds === []);
	}

	/** @param list<int> $visibleFileIds
	 * @return array<string, mixed> */
	private function state(Gallery $gallery, ?Guest $guest, CollaborationReadScope $scope, int $cursor, array $visibleFileIds, bool $allFiles): array {
		$settings = $this->settings($gallery);
		$visibleFileIds = array_values(array_unique(array_filter(array_map('intval', $visibleFileIds), static fn (int $id): bool => $id > 0)));
		if (count($visibleFileIds) > 200) throw new InvalidArgumentException('Too many visible media IDs');
		$state = $this->repository->state($gallery->getId(), $scope, $cursor, $visibleFileIds, $allFiles);
		if (($state['unchanged'] ?? false) === true) return ['unchanged' => true, 'cursor' => $cursor];
		$feedback = $state['feedback'];
		$comments = $state['comments'];
		$selections = $state['selections'];
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
		$events = array_map(static fn (array $row): array => [
			'id' => (int)$row['id'],
			'type' => $row['event_type'],
			'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
			'createdAt' => (int)$row['created_at'],
		], $state['events']);
		$nextCursor = $scope->isEmpty() ? 0 : $cursor;
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
				'visibility' => $settings->review->visibility->value,
				'colorLabels' => $settings->review->colorLabels,
				'requiresSession' => $guest === null,
				'features' => [
					'likes' => $settings->review->likes && $this->capabilities->feature('likes'),
					'colors' => $settings->review->colors && $this->capabilities->feature('colors'),
					'comments' => $settings->review->comments && $this->capabilities->feature('comments'),
					'annotations' => $settings->review->annotations && $this->capabilities->feature('annotations'),
					'selections' => $settings->review->selections && $this->capabilities->feature('selections'),
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
			'delta' => (bool)($state['delta'] ?? false),
		];
	}

	/** @return array{items:list<array<string,mixed>>,total:int,nextCursor:?string} */
	public function ownerSelectionPage(Gallery $gallery, int $limit, ?string $cursor, ScopedCursorCodec $cursors): array {
		$limit = max(1, min(100, $limit));
		$scope = 'owner-selections:' . $gallery->getId();
		$rows = $this->repository->selectionPage($gallery->getId(), null, $cursors->decode($cursor, $scope), $limit + 1);
		$hasMore = count($rows) > $limit;
		if ($hasMore) array_pop($rows);
		$this->repository->decorateSelections($rows);
		$items = $this->presentSelections($rows, null);
		$last = $rows === [] ? null : $rows[array_key_last($rows)];
		return ['items' => $items, 'total' => $this->repository->selectionCount($gallery->getId()), 'nextCursor' => $hasMore && $last !== null ? $cursors->encode($scope, (int)$last['id']) : null];
	}

	public function toggleLike(Gallery $gallery, Guest $guest, int $fileId): bool {
		return $this->atomic(function () use ($gallery, $guest, $fileId): bool {
			$this->capabilities->assertFeature('likes');
			$settings = $this->assertCollaboration($gallery, $fileId);
			if (!$settings->review->likes) {
				throw new InvalidArgumentException('Likes are disabled');
			}
			$existing = $this->repository->feedbackId($gallery->getId(), $guest->getId(), $fileId, 'like');
			if ($existing !== null) {
				$this->repository->deleteFeedback($existing);
				$liked = false;
			} else {
				$this->assertQuota('proofing_feedback', $gallery->getId(), $guest->getId(), self::MAX_FEEDBACK_PER_GALLERY, self::MAX_FEEDBACK_PER_GALLERY);
				$this->repository->insertFeedback($gallery->getId(), $guest->getId(), $fileId, 'like', '1', $this->clock->getTime());
				$liked = true;
			}
			$this->event($gallery, $guest, 'like.changed', ['fileId' => $fileId, 'liked' => $liked]);
			return $liked;
		});
	}

	public function saveRating(PublicLink $link, Gallery $gallery, Guest $guest, int $fileId, int $rating, string $pick): GuestRating {
		return $this->atomic(function () use ($link, $gallery, $guest, $fileId, $rating, $pick): GuestRating {
			$value = $this->guestRatings->save($link, $guest, $fileId, $rating, $pick);
			$this->event($gallery, $guest, 'rating.changed', ['fileId' => $fileId]);
			return $value;
		});
	}

	public function setColor(Gallery $gallery, Guest $guest, int $fileId, ?string $value): void {
		$this->atomic(function () use ($gallery, $guest, $fileId, $value): void {
			$this->capabilities->assertFeature('colors');
			$settings = $this->assertCollaboration($gallery, $fileId);
			if (!$settings->review->colors) {
				throw new InvalidArgumentException('Color states are disabled');
			}
			$enabledLabels = array_values(array_filter(
				$settings->review->colorLabels,
				static fn (string $_label, int $index): bool => $settings->review->colorEnabled[$index],
				ARRAY_FILTER_USE_BOTH,
			));
			if ($value !== null && !in_array($value, $enabledLabels, true)) {
				throw new InvalidArgumentException('Unknown color workflow state');
			}
			$id = $this->repository->feedbackId($gallery->getId(), $guest->getId(), $fileId, 'color');
			if ($value === null && $id !== null) {
				$this->repository->deleteFeedback($id);
			} elseif ($value !== null && $id === null) {
				$this->assertQuota('proofing_feedback', $gallery->getId(), $guest->getId(), self::MAX_FEEDBACK_PER_GALLERY, self::MAX_FEEDBACK_PER_GALLERY);
				$this->repository->insertFeedback($gallery->getId(), $guest->getId(), $fileId, 'color', $value, $this->clock->getTime());
			} elseif ($value !== null) {
				$this->repository->updateFeedback($id, $value, $this->clock->getTime());
			}
			$this->event($gallery, $guest, 'color.changed', ['fileId' => $fileId, 'value' => $value]);
		});
	}

	/** @param array<string, int>|null $annotation */
	public function addComment(Gallery $gallery, Guest $guest, int $fileId, string $body, ?array $annotation): int {
		return $this->atomic(function () use ($gallery, $guest, $fileId, $body, $annotation): int {
			$this->capabilities->assertFeature('comments');
			if ($annotation !== null) $this->capabilities->assertFeature('annotations');
			$settings = $this->assertCollaboration($gallery, $fileId);
			if (!$settings->review->comments) {
				throw new InvalidArgumentException('Comments are disabled');
			}
			if ($annotation !== null && !$settings->review->annotations) {
				throw new InvalidArgumentException('Image annotations are disabled');
			}
			if ($annotation !== null && !str_starts_with($this->resolveMedia($gallery, $fileId)->getMimeType(), 'image/')) {
				throw new InvalidArgumentException('Image annotations require an image file');
			}
			$body = trim($body);
			if ($body === '' || mb_strlen($body) > 5000) {
				throw new InvalidArgumentException('Comment must contain between 1 and 5000 characters');
			}
			$this->assertQuota('proofing_comments', $gallery->getId(), $guest->getId(), self::MAX_COMMENTS_PER_GALLERY, self::MAX_COMMENTS_PER_GUEST);
			$commentId = $this->repository->insertComment(
				$gallery->getId(), $guest->getId(), $fileId, $body, $annotation, $this->clock->getTime(),
			);
			$this->event($gallery, $guest, 'comment.created', ['fileId' => $fileId, 'commentId' => $commentId]);
			return $commentId;
		});
	}

	public function deleteComment(Gallery $gallery, Guest $guest, int $commentId): void {
		$this->atomic(function () use ($gallery, $guest, $commentId): void {
			$this->capabilities->assertFeature('comments');
			$fileId = $this->ownedCommentFileId($gallery, $guest, $commentId);
			if (!$this->repository->deleteComment($gallery->getId(), $guest->getId(), $commentId, $this->clock->getTime())) {
				throw new InvalidArgumentException('Comment cannot be deleted');
			}
			$this->event($gallery, $guest, 'comment.deleted', ['fileId' => $fileId, 'commentId' => $commentId]);
		});
	}

	public function ownedCommentFileId(Gallery $gallery, Guest $guest, int $commentId): int {
		$fileId = $this->repository->ownedCommentFileId($gallery->getId(), $guest->getId(), $commentId);
		if ($fileId === null) throw new InvalidArgumentException('Comment not found');
		return $fileId;
	}

	public function updateComment(Gallery $gallery, Guest $guest, int $commentId, string $body): void {
		$this->atomic(function () use ($gallery, $guest, $commentId, $body): void {
			$this->capabilities->assertFeature('comments');
			$fileId = $this->ownedCommentFileId($gallery, $guest, $commentId);
			$body = trim($body);
			if ($body === '' || mb_strlen($body) > 5000) {
				throw new InvalidArgumentException('Comment must contain between 1 and 5000 characters');
			}
			if (!$this->repository->updateComment($gallery->getId(), $guest->getId(), $commentId, $body, $this->clock->getTime())) {
				throw new InvalidArgumentException('Comment cannot be edited');
			}
			$this->event($gallery, $guest, 'comment.updated', ['fileId' => $fileId, 'commentId' => $commentId]);
		});
	}

	/** @param list<int> $fileIds */
	public function saveSelection(Gallery $gallery, Guest $guest, string $name, string $message, array $fileIds): string {
		return $this->atomic(function () use ($gallery, $guest, $name, $message, $fileIds): string {
			$this->capabilities->assertFeature('selections');
			$this->assertCollaborationMode($gallery);
			if (!$this->settings($gallery)->review->selections) {
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
			$this->assertQuota('proofing_selections', $gallery->getId(), $guest->getId(), self::MAX_SELECTIONS_PER_GALLERY, self::MAX_SELECTIONS_PER_GUEST);
			$publicId = $this->uuid();
			$now = $this->clock->getTime();
			$this->repository->insertSelection(
				$gallery->getId(), $guest->getId(), $publicId, $name, $message, $fileIds, $now,
			);
			$this->event($gallery, $guest, 'selection.created', ['selectionId' => $publicId, 'count' => count($fileIds)]);
			$this->markResponseReceived($gallery, $now);
			return $publicId;
		});
	}

	private function markResponseReceived(Gallery $gallery, int $now): void {
		$this->repository->markResponseReceived($gallery->getId(), $now);
	}

	/**
	 * @param list<string> $requestedFields
	 * @return array{content: string, filename: string, mimeType: string}
	 */
	public function exportSelection(Gallery $gallery, ?Guest $guest, string $publicId, string $format, array $requestedFields = []): array {
		$this->capabilities->assertFeature('selections');
		$row = $this->repository->selection($gallery->getId(), $publicId);
		if ($row === null
			|| ($guest !== null && $this->settings($gallery)->review->visibility === FeedbackVisibility::Private
				&& (int)$row['guest_id'] !== $guest->getId())) {
			throw new InvalidArgumentException('Selection not found');
		}
		$fileIds = [];
		foreach ($this->repository->selectionFileIds((int)$row['id']) as $fileId) {
			try {
				$this->resolveMedia($gallery, (int)$fileId);
				$fileIds[] = (int)$fileId;
			} catch (\Throwable) {
				// Files removed after selection creation are intentionally omitted.
			}
		}
		$names = array_map(fn (int $fileId): string => $this->resolveMedia($gallery, $fileId)->getName(), $fileIds);
		$base = preg_replace('/[^a-z0-9._-]+/i', '-', (string)$row['name']) ?: 'selection';
		if ($format === 'csv' || $format === 'preview') {
			$allowed = $guest === null
				? ['filename', 'path', 'mimeType', 'size', 'modifiedAt', 'ownerRating', 'ownerPick', 'ownerColor', 'guestAverage', 'guestCount', 'selection', 'comments']
				: ['filename', 'rating', 'pick'];
			$fields = array_values(array_unique(array_intersect($allowed, array_map('strval', $requestedFields))));
			if ($fields === []) $fields = ['filename'];
			$rows = $this->composeExportRows($gallery, $guest, $fileIds, $fields, (string)$row['name']);
			$content = "\xEF\xBB\xBF" . $this->csv->encode([$fields, ...array_map(
				static fn (array $values): array => array_map(static fn (string $field): string => (string)($values[$field] ?? ''), $fields),
				$rows,
			)]);
			return ['content' => $content, 'filename' => $base . ($format === 'preview' ? '-preview.csv' : '.csv'), 'mimeType' => 'text/csv; charset=utf-8'];
		}
		return match ($format) {
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

	/** @return list<int> */
	public function guestSelectionFileIds(Gallery $gallery, Guest $guest, string $publicId): array {
		$row = $this->repository->selection($gallery->getId(), $publicId);
		if ($row === null || ($this->settings($gallery)->review->visibility === FeedbackVisibility::Private && (int)$row['guest_id'] !== $guest->getId())) {
			throw new InvalidArgumentException('Selection not found');
		}
		return $this->repository->selectionFileIds((int)$row['id']);
	}

	/**
	 * @param list<int> $fileIds
	 * @param list<string> $fields
	 * @return list<array<string, int|float|string>>
	 */
	private function composeExportRows(Gallery $gallery, ?Guest $guest, array $fileIds, array $fields, string $selectionName): array {
		if ($fileIds === []) return [];
		$culls = $guest === null ? $this->culling->forFiles($gallery->getOwnerUid(), $fileIds) : [];
		$aggregates = $guest === null ? array_column($this->guestRatings->aggregate($gallery, $fileIds)['items'], null, 'fileId') : [];
		$guestValues = $guest === null ? [] : array_column(array_map(static fn (\OCA\ProofingGallery\Db\GuestRating $value): array => $value->jsonSerialize(), $this->guestRatings->forGuest($guest)), null, 'fileId');
		$comments = [];
		if ($guest === null && in_array('comments', $fields, true)) {
			$comments = $this->repository->commentsByFileIds($gallery->getId(), $fileIds);
		}
		$root = $gallery->getSourceType() === 'folder' ? $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()) : null;
		$result = [];
		foreach ($fileIds as $fileId) {
			$file = $this->resolveMedia($gallery, $fileId);
			$cull = $culls[$fileId] ?? null;
			$aggregate = $aggregates[$fileId] ?? null;
			$rating = $guestValues[$fileId] ?? null;
			$result[] = [
				'filename' => $file->getName(),
				'path' => $root === null ? $file->getName() : $root->getRelativePath($file->getPath()),
				'mimeType' => $file->getMimeType(),
				'size' => (int)$file->getSize(),
				'modifiedAt' => gmdate(DATE_ATOM, $file->getMTime()),
				'ownerRating' => $cull?->getRating() ?? 0,
				'ownerPick' => $cull?->getPickState() ?? 'none',
				'ownerColor' => $cull?->getColor() ?? 'none',
				'guestAverage' => $aggregate['average'] ?? '',
				'guestCount' => $aggregate['count'] ?? 0,
				'selection' => $selectionName,
				'comments' => implode(' | ', $comments[$fileId] ?? []),
				'rating' => $rating['rating'] ?? 0,
				'pick' => $rating['pick'] ?? 'none',
			];
		}
		return $result;
	}

	/** @return list<array<string, mixed>> */
	public function ownerSelections(Gallery $gallery): array {
		return array_reverse($this->presentSelections($this->repository->selections($gallery->getId(), null), null));
	}

	/**
	 * @param list<string> $fields
	 * @return array{content: string, filename: string, mimeType: string}
	 */
	public function exportOwnerSelection(Gallery $gallery, string $publicId, string $format, array $fields = []): array {
		return $this->exportSelection($gallery, null, $publicId, $format, $fields);
	}

	public function updateOwnerSelection(Gallery $gallery, string $publicId, string $name, string $status): void {
		$this->atomic(function () use ($gallery, $publicId, $name, $status): void {
			$name = trim($name);
			if ($name === '' || mb_strlen($name) > 120 || !in_array($status, ['open', 'completed'], true)) {
				throw new InvalidArgumentException('Invalid selection name or status');
			}
			$selection = $this->repository->selection($gallery->getId(), $publicId);
			if ($selection === null) throw new InvalidArgumentException('Selection not found');
			$now = $this->clock->getTime();
			if (!$this->repository->updateSelection($gallery->getId(), $publicId, $name, $status, $now)) {
				throw new InvalidArgumentException('Selection not found');
			}
			$this->repository->insertOwnerEvent(
				$gallery->getId(), (int)$selection['guest_id'], $gallery->getOwnerUid(),
				'selection.updated', ['selectionId' => $publicId], $now,
			);
		});
	}

	public function deleteOwnerSelection(Gallery $gallery, string $publicId): void {
		$this->atomic(function () use ($gallery, $publicId): void {
			$selection = $this->repository->selection($gallery->getId(), $publicId);
			if ($selection === null) throw new InvalidArgumentException('Selection not found');
			if (!$this->repository->deleteSelection($gallery->getId(), $publicId)) throw new InvalidArgumentException('Selection not found');
			$this->repository->insertOwnerEvent(
				$gallery->getId(), (int)$selection['guest_id'], $gallery->getOwnerUid(),
				'selection.deleted', ['selectionId' => $publicId, 'deleted' => true], $this->clock->getTime(),
			);
		});
	}

	private function settings(Gallery $gallery): GallerySettings {
		return GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
	}

	private function assertQuota(string $table, int $galleryId, int $guestId, int $galleryLimit, int $guestLimit): void {
		if ($this->repository->hasAtLeastRows($table, $galleryId, $galleryLimit)
			|| $this->repository->hasAtLeastRows($table, $galleryId, $guestLimit, $guestId)) {
			throw new InvalidArgumentException('Collaboration data limit reached');
		}
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

	public function assertMediaAvailable(Gallery $gallery, int $fileId): void {
		$this->resolveMedia($gallery, $fileId);
	}

	private function assertCollaborationMode(Gallery $gallery): GallerySettings {
		$settings = $this->settings($gallery);
		if ($settings->mode !== GalleryMode::Collaboration) {
			throw new InvalidArgumentException('Collaboration is disabled');
		}
		return $settings;
	}

	/** @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function presentComments(array $rows, ?Guest $viewer): array {
		return array_map(
			static fn (array $row): array => [
				'id' => (int)$row['id'],
				'fileId' => (int)$row['file_id'],
				'body' => $row['body'],
				'createdAt' => (int)$row['created_at'],
				'editedAt' => $row['edited_at'] === null ? null : (int)$row['edited_at'],
				'deletedAt' => $row['deleted_at'] === null ? null : (int)$row['deleted_at'],
				'mine' => $viewer !== null && (int)$row['guest_id'] === $viewer->getId(),
				'author' => (string)$row['author'],
				'annotations' => $row['annotations'],
			],
			$rows,
		);
	}

	/** @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function presentSelections(array $rows, ?Guest $viewer): array {
		return array_map(static fn (array $row): array => [
			'id' => $row['public_id'],
			'name' => $row['name'],
			'message' => $row['message'],
			'status' => $row['status'],
			'fileIds' => $row['fileIds'],
			'updatedAt' => (int)$row['updated_at'],
			'mine' => $viewer !== null && (int)$row['guest_id'] === $viewer->getId(),
			'author' => (string)$row['author'],
		], $rows);
	}

	/** @param array<string, mixed> $payload */
	private function event(Gallery $gallery, Guest $guest, string $type, array $payload): void {
		$now = $this->clock->getTime();
		$eventId = $this->repository->insertEvent($gallery->getId(), $guest->getId(), $type, $payload, $now);
		$staged = $this->notifications->stage($gallery, $eventId, $type, $now);
		$this->stagedActivities[] = [
			'gallery' => $gallery,
			'type' => $type,
			'createdAt' => $now,
			'recipients' => $staged['recipients'],
			'nativeStateIds' => $staged['nativeStateIds'],
		];
	}

	private function atomic(callable $callback): mixed {
		$ownsTransaction = !$this->db->inTransaction();
		$activityOffset = count($this->stagedActivities);
		if ($ownsTransaction) $this->db->beginTransaction();
		try {
			$result = $callback();
			if ($ownsTransaction) $this->db->commit();
		} catch (\Throwable $exception) {
			if ($ownsTransaction) $this->db->rollBack();
			array_splice($this->stagedActivities, $activityOffset);
			throw $exception;
		}
		if (!$ownsTransaction) {
			// The outer transaction owns publication. Native states remain pending for
			// the background dispatcher; never retain them for an unrelated request.
			array_splice($this->stagedActivities, $activityOffset);
			return $result;
		}
		$activities = array_splice($this->stagedActivities, $activityOffset);
		foreach ($activities as $activity) {
			$this->notifications->publishActivity(
				$activity['gallery'],
				$activity['type'],
				$activity['createdAt'],
				$activity['recipients'],
				$activity['nativeStateIds'],
			);
		}
		return $result;
	}

	private function uuid(): string {
		$hex = bin2hex(random_bytes(16));
		return sprintf('%s-%s-4%s-%s%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), dechex((hexdec($hex[16]) & 3) | 8), substr($hex, 17, 3), substr($hex, 20, 12));
	}
}
