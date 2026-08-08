<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\ActivityRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCP\AppFramework\Utility\ITimeFactory;

final class ActivityService {
	public function __construct(
		private ActivityRepository $repository,
		private ITimeFactory $clock,
		private NotificationService $notifications,
	) {
	}

	/** @param array<string, mixed> $payload */
	public function record(Gallery $gallery, ?Guest $guest, string $type, array $payload): void {
		$now = $this->clock->getTime();
		$eventId = $this->repository->insert($gallery->getId(), $guest?->getId(), $type, $payload, $now);
		$this->notifications->queue($gallery, $eventId, $type, $now);
	}

	/** @param array<string, mixed> $payload */
	public function recordOnce(Gallery $gallery, string $type, string $dedupeKey, array $payload): bool {
		if ($this->repository->existsWithDedupeKey($gallery->getId(), $type, $dedupeKey)) return false;
		$this->record($gallery, null, $type, ['dedupeKey' => $dedupeKey, ...$payload]);
		return true;
	}

	/** @return list<array<string, mixed>> */
	public function list(Gallery $gallery, int $cursor = 0, string $type = ''): array {
		return array_map(static fn (array $row): array => [
			'id' => (int)$row['id'],
			'type' => $row['event_type'],
			'actor' => $row['display_name'] ?? $row['actor_uid'] ?? 'Gallery manager',
			'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
			'createdAt' => (int)$row['created_at'],
		], $this->repository->list($gallery->getId(), $cursor, $type));
	}

	/** @return array{items:list<array<string,mixed>>,total:int,nextCursor:?string} */
	public function page(Gallery $gallery, int $limit, ?string $cursor, string $type, ScopedCursorCodec $cursors): array {
		$limit = max(1, min(100, $limit));
		$scope = 'gallery-activity:' . $gallery->getId() . ':' . $type;
		$rows = $this->repository->page($gallery->getId(), $cursors->decode($cursor, $scope), $type, $limit + 1);
		$hasMore = count($rows) > $limit;
		if ($hasMore) array_pop($rows);
		$items = array_map(static fn (array $row): array => [
			'id' => (int)$row['id'], 'type' => $row['event_type'], 'actor' => $row['display_name'] ?? $row['actor_uid'] ?? 'Gallery manager',
			'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR), 'createdAt' => (int)$row['created_at'],
		], $rows);
		$last = $rows === [] ? null : $rows[array_key_last($rows)];
		return ['items' => $items, 'total' => $this->repository->countGallery($gallery->getId(), $type), 'nextCursor' => $hasMore && $last !== null ? $cursors->encode($scope, (int)$last['id']) : null];
	}
}
