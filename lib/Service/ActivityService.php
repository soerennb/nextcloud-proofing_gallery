<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCP\Activity\IManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ActivityService {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $clock,
		private IManager $activity,
		private NotificationService $notifications,
	) {
	}

	/** @param array<string, mixed> $payload */
	public function record(Gallery $gallery, ?Guest $guest, string $type, array $payload): void {
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_events')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guest?->getId(), IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter(null),
			'event_type' => $qb->createNamedParameter($type),
			'payload' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$eventId = (int)$this->db->lastInsertId('proofing_events');
		$this->notifications->queue($gallery, $eventId, $type, $now);

		$message = $this->message($gallery, $guest, $type, $payload);
		$event = $this->activity->generateEvent()
			->setApp(Application::APP_ID)
			->setType('proofing_gallery')
			->setAffectedUser($gallery->getOwnerUid())
			->setAuthor($gallery->getOwnerUid())
			->setTimestamp($now)
			->setSubject('gallery_event', ['message' => $message])
			->setObject('proofing_gallery', $gallery->getId(), $gallery->getTitle());
		$this->activity->publish($event);

	}

	/** @return list<array<string, mixed>> */
	public function list(Gallery $gallery, int $cursor = 0, string $type = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.*', 'g.display_name')
			->from('proofing_events', 'e')
			->leftJoin('e', 'proofing_guests', 'g', $qb->expr()->eq('e.guest_id', 'g.id'))
			->where($qb->expr()->eq('e.gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('e.id', $qb->createNamedParameter(max(0, $cursor), IQueryBuilder::PARAM_INT)))
			->orderBy('e.id', 'DESC')
			->setMaxResults(200);
		if ($type !== '') {
			$qb->andWhere($qb->expr()->like('e.event_type', $qb->createNamedParameter($this->db->escapeLikeParameter($type) . '%')));
		}
		return array_map(static fn (array $row): array => [
			'id' => (int)$row['id'],
			'type' => $row['event_type'],
			'actor' => $row['display_name'] ?? $row['actor_uid'] ?? 'Gallery manager',
			'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
			'createdAt' => (int)$row['created_at'],
		], $qb->executeQuery()->fetchAllAssociative());
	}

	/** @param array<string, mixed> $payload */
	private function message(Gallery $gallery, ?Guest $guest, string $type, array $payload): string {
		$actor = $guest?->getDisplayName() ?? 'A gallery manager';
		return match ($type) {
			'upload.received' => sprintf('%s uploaded %s to “%s”', $actor, $payload['filename'] ?? 'a file', $gallery->getTitle()),
			'upload.accepted' => sprintf('An upload was accepted into “%s”', $gallery->getTitle()),
			'upload.rejected' => sprintf('An upload was rejected from “%s”', $gallery->getTitle()),
			default => sprintf('New activity in “%s”', $gallery->getTitle()),
		};
	}

}
