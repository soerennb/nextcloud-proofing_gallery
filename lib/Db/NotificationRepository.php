<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class NotificationRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string, mixed>> */
	public function subscriptions(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_notify_subs')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('user_uid')->executeQuery());
	}

	/** @return array<string, mixed>|null */
	public function subscription(int $galleryId, string $userUid): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_notify_subs')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	/**
	 * @param list<string> $eventTypes
	 * @param list<string> $nativeEventTypes
	 * @return array<string, mixed>
	 */
	public function saveSubscription(
		int $galleryId,
		string $userUid,
		array $eventTypes,
		bool $emailEnabled,
		bool $nativeEnabled,
		array $nativeEventTypes,
		string $frequency,
		string $locale,
		string $newToken,
		int $now,
	): array {
		$existing = $this->subscription($galleryId, $userUid);
		$qb = $this->db->getQueryBuilder();
		if ($existing === null) {
			$qb->insert('proofing_notify_subs')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'user_uid' => $qb->createNamedParameter($userUid),
				'event_types' => $qb->createNamedParameter(json_encode($eventTypes, JSON_THROW_ON_ERROR)),
				'email_enabled' => $qb->createNamedParameter($emailEnabled, IQueryBuilder::PARAM_BOOL),
				'native_enabled' => $qb->createNamedParameter($nativeEnabled, IQueryBuilder::PARAM_BOOL),
				'native_event_types' => $qb->createNamedParameter(json_encode($nativeEventTypes, JSON_THROW_ON_ERROR)),
				'frequency' => $qb->createNamedParameter($frequency),
				'locale' => $qb->createNamedParameter($locale),
				'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'unsubscribe_token' => $qb->createNamedParameter($newToken),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} else {
			$token = (bool)$existing['active'] ? (string)$existing['unsubscribe_token'] : $newToken;
			$qb->update('proofing_notify_subs')
				->set('event_types', $qb->createNamedParameter(json_encode($eventTypes, JSON_THROW_ON_ERROR)))
				->set('email_enabled', $qb->createNamedParameter($emailEnabled, IQueryBuilder::PARAM_BOOL))
				->set('native_enabled', $qb->createNamedParameter($nativeEnabled, IQueryBuilder::PARAM_BOOL))
				->set('native_event_types', $qb->createNamedParameter(json_encode($nativeEventTypes, JSON_THROW_ON_ERROR)))
				->set('frequency', $qb->createNamedParameter($frequency))
				->set('locale', $qb->createNamedParameter($locale))
				->set('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set('unsubscribe_token', $qb->createNamedParameter($token))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return $this->subscription($galleryId, $userUid)
			?? throw new \RuntimeException('Subscription could not be saved');
	}

	public function deleteSubscription(int $galleryId, int $id): ?string {
		$qb = $this->db->getQueryBuilder();
		$userUid = $qb->select('user_uid')->from('proofing_notify_subs')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne();
		if ($userUid === false) return null;
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_notify_queue')
				->where($qb->expr()->eq('subscription_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$deleted = $qb->delete('proofing_notify_subs')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$this->db->commit();
			return $deleted === 1 ? (string)$userUid : null;
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	public function unsubscribe(string $token, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_notify_subs')
			->set('email_enabled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('unsubscribe_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('email_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeStatement() === 1;
	}

	/** @return list<array<string, mixed>> */
	public function activeSubscriptions(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('id', 'user_uid', 'event_types', 'frequency', 'email_enabled', 'native_enabled', 'native_event_types')
			->from('proofing_notify_subs')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery());
	}

	public function enqueue(int $subscriptionId, int $eventId, int $availableAt, int $createdAt): void {
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('proofing_notify_queue')->values([
				'subscription_id' => $qb->createNamedParameter($subscriptionId, IQueryBuilder::PARAM_INT),
				'event_id' => $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter('pending'),
				'available_at' => $qb->createNamedParameter($availableAt, IQueryBuilder::PARAM_INT),
				'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (Exception) {
			// The subscription/event unique key makes retries idempotent.
		}
	}

	/** @return array<int, list<int>> */
	public function pendingBySubscription(int $now): array {
		$this->recoverStaleClaims($now);
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('q.id', 'q.subscription_id')->from('proofing_notify_queue', 'q')
			->innerJoin('q', 'proofing_notify_subs', 's', $qb->expr()->eq('q.subscription_id', 's.id'))
			->where($qb->expr()->eq('q.status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('q.available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('s.email_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('q.id')->setMaxResults(200)->executeQuery());
		$result = [];
		foreach ($rows as $row) $result[(int)$row['subscription_id']][] = (int)$row['id'];
		return $result;
	}

	/** @param list<int> $ids
	 * @return list<int>
	 */
	public function claim(array $ids, int $now): array {
		$claimed = [];
		foreach ($ids as $id) {
			$qb = $this->db->getQueryBuilder();
			$count = $qb->update('proofing_notify_queue')
				->set('status', $qb->createNamedParameter('sending'))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
				->executeStatement();
			if ($count === 1) $claimed[] = $id;
		}
		return $claimed;
	}

	/** @return array<string, mixed>|null */
	public function activeEmailSubscription(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_notify_subs')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('email_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	/** @param list<int> $queueIds
	 * @return list<mixed>
	 */
	public function eventTypes(array $queueIds): array {
		$events = [];
		foreach (array_chunk($queueIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$events = [...$events, ...QueryResult::column($qb->select('e.event_type')->from('proofing_notify_queue', 'q')
				->innerJoin('q', 'proofing_events', 'e', $qb->expr()->eq('q.event_id', 'e.id'))
				->where($qb->expr()->in('q.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeQuery())];
		}
		return $events;
	}

	/** @param list<int> $ids */
	public function markSent(array $ids, int $now): void {
		$this->updateChunks($ids, function (IQueryBuilder $qb, array $chunk) use ($now): void {
			$qb->update('proofing_notify_queue')->set('status', $qb->createNamedParameter('sent'))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		});
	}

	/** @param list<int> $ids */
	public function retry(array $ids, int $now): void {
		$this->updateChunks($ids, function (IQueryBuilder $qb, array $chunk) use ($now): void {
			$qb->update('proofing_notify_queue')->set('status', $qb->createNamedParameter('pending'))
				->set('attempts', $qb->createFunction('attempts + 1'))
				->set('available_at', $qb->createNamedParameter($now + 300, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		});
	}

	private function recoverStaleClaims(int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_notify_queue')->set('status', $qb->createNamedParameter('pending'))
			->where($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($now - 900, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @param list<int> $ids
	 * @param callable(IQueryBuilder, list<int>): void $update
	 */
	private function updateChunks(array $ids, callable $update): void {
		foreach (array_chunk($ids, 500) as $chunk) $update($this->db->getQueryBuilder(), $chunk);
	}
}
