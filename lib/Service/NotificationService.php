<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\ManagerMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Activity\IManager as ActivityManager;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;

final class NotificationService {
	public const EVENT_TYPES = [
		'comment.created', 'comment.updated', 'selection.created',
		'like.changed', 'color.changed', 'upload.received', 'upload.accepted', 'upload.rejected',
	];

	public function __construct(
		private IDBConnection $db,
		private GalleryAccessService $access,
		private ManagerMapper $managers,
		private GalleryMapper $galleries,
		private IUserManager $users,
		private ITimeFactory $clock,
		private ISecureRandom $random,
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private NativeNotificationService $native,
		private ActivityManager $activity,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(string $ownerUid, int $galleryId): array {
		$this->access->owner($ownerUid, $galleryId);
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('*')->from('proofing_notify_subs')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('user_uid')->executeQuery());
		return array_map(fn (array $row): array => $this->present($row), $rows);
	}

	/**
	 * @param list<string> $eventTypes
	 * @param list<string> $nativeEventTypes
	 * @return array<string, mixed>
	 */
	public function save(
		string $ownerUid,
		int $galleryId,
		string $recipientUid,
		array $eventTypes,
		string $frequency,
		string $locale,
		bool $emailEnabled = true,
		bool $nativeEnabled = false,
		array $nativeEventTypes = [],
	): array {
		$this->access->owner($ownerUid, $galleryId);
		$this->assertEligible($ownerUid, $galleryId, $recipientUid, $emailEnabled);
		$eventTypes = array_values(array_unique($eventTypes));
		$nativeEventTypes = array_values(array_unique($nativeEventTypes));
		if (($emailEnabled && $eventTypes === []) || array_diff($eventTypes, self::EVENT_TYPES) !== []) {
			throw new InvalidArgumentException('Choose one or more supported gallery events');
		}
		if (($nativeEnabled && $nativeEventTypes === []) || array_diff($nativeEventTypes, NativeNotificationService::EVENT_TYPES) !== []) {
			throw new InvalidArgumentException('Choose one or more important Nextcloud events');
		}
		if (!$emailEnabled && !$nativeEnabled) throw new InvalidArgumentException('Enable at least one notification channel');
		if (!in_array($frequency, ['immediate', 'daily'], true) || !in_array($locale, ['auto', 'en', 'de'], true)) {
			throw new InvalidArgumentException('Invalid notification frequency or locale');
		}
		$now = $this->clock->getTime();
		$existing = $this->findSubscription($galleryId, $recipientUid);
		$qb = $this->db->getQueryBuilder();
		if ($existing === null) {
			$qb->insert('proofing_notify_subs')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'user_uid' => $qb->createNamedParameter($recipientUid),
				'event_types' => $qb->createNamedParameter(json_encode($eventTypes, JSON_THROW_ON_ERROR)),
				'email_enabled' => $qb->createNamedParameter($emailEnabled, IQueryBuilder::PARAM_BOOL),
				'native_enabled' => $qb->createNamedParameter($nativeEnabled, IQueryBuilder::PARAM_BOOL),
				'native_event_types' => $qb->createNamedParameter(json_encode($nativeEventTypes, JSON_THROW_ON_ERROR)),
				'frequency' => $qb->createNamedParameter($frequency),
				'locale' => $qb->createNamedParameter($locale),
				'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'unsubscribe_token' => $qb->createNamedParameter($this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC)),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			$existing = $this->findSubscription($galleryId, $recipientUid);
		} else {
			$token = (bool)$existing['active']
				? (string)$existing['unsubscribe_token']
				: $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
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
			$existing = $this->findSubscription($galleryId, $recipientUid);
		}
		if (!$nativeEnabled) $this->native->processGalleryUser($galleryId, $recipientUid);
		return $this->present($existing ?? throw new \RuntimeException('Subscription could not be saved'));
	}

	public function delete(string $ownerUid, int $galleryId, int $id): void {
		$this->access->owner($ownerUid, $galleryId);
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('user_uid')->from('proofing_notify_subs')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeQuery());
		if ($row === false) throw new DoesNotExistException('Notification subscription not found');
		$this->native->processGalleryUser($galleryId, (string)$row['user_uid']);
		$queue = $this->db->getQueryBuilder();
		$queue->delete('proofing_notify_queue')
			->where($queue->expr()->eq('subscription_id', $queue->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_notify_subs')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) throw new DoesNotExistException('Notification subscription not found');
	}

	public function unsubscribe(string $token): bool {
		if (!preg_match('/^[A-Za-z0-9]{48}$/', $token)) return false;
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_notify_subs')
			->set('email_enabled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('unsubscribe_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('email_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		return $qb->executeStatement() === 1;
	}

	public function queue(Gallery $gallery, int $eventId, string $eventType, int $createdAt): void {
		if (!in_array($eventType, self::EVENT_TYPES, true)) return;
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('id', 'user_uid', 'event_types', 'frequency', 'email_enabled', 'native_enabled', 'native_event_types')->from('proofing_notify_subs')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery());
		$rows = array_values(array_filter($rows, function (array $row) use ($gallery): bool {
			try {
				$this->access->view((string)$row['user_uid'], (int)$gallery->getId());
				return true;
			} catch (\Throwable) {
				// Revoked managers must not receive gallery titles or activity details.
				$this->native->processGalleryUser((int)$gallery->getId(), (string)$row['user_uid']);
				return false;
			}
		}));
		$recipients = array_values(array_unique([$gallery->getOwnerUid(), ...array_map(static fn (array $row): string => (string)$row['user_uid'], $rows)]));
		foreach ($recipients as $recipient) {
			try {
				$event = $this->activity->generateEvent()
					->setApp(Application::APP_ID)
					->setType(Application::APP_ID)
					->setAffectedUser($recipient)
					->setTimestamp($createdAt)
					->setSubject('gallery_event', ['eventType' => $eventType, 'galleryTitle' => $gallery->getTitle()])
					->setObject('proofing_gallery', (int)$gallery->getId(), $gallery->getTitle());
				$this->activity->publish($event);
			} catch (\Throwable) {
				// Activity integration must never fail the underlying gallery action.
			}
		}
		foreach ($rows as $row) {
			$nativeTypes = json_decode((string)($row['native_event_types'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
			if ((bool)$row['native_enabled'] && in_array($eventType, $nativeTypes, true)) {
				$this->native->signal((int)$gallery->getId(), (string)$row['user_uid'], $eventId, $eventType);
			}
			if (!(bool)$row['email_enabled']) continue;
			$types = json_decode($row['event_types'], true, flags: JSON_THROW_ON_ERROR);
			if (!in_array($eventType, $types, true)) continue;
			$availableAt = $row['frequency'] === 'daily'
				? ((int)floor($createdAt / 86400) + 1) * 86400
				: $createdAt;
			$insert = $this->db->getQueryBuilder();
			try {
				$insert->insert('proofing_notify_queue')->values([
					'subscription_id' => $insert->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT),
					'event_id' => $insert->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
					'status' => $insert->createNamedParameter('pending'),
					'available_at' => $insert->createNamedParameter($availableAt, IQueryBuilder::PARAM_INT),
					'attempts' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'created_at' => $insert->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
					'updated_at' => $insert->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			} catch (Exception) {
				// The subscription/event unique key makes retries idempotent.
			}
		}
	}

	public function dispatch(): int {
		$now = $this->clock->getTime();
		$this->recoverStaleClaims($now);
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('q.id', 'q.subscription_id')->from('proofing_notify_queue', 'q')
			->innerJoin('q', 'proofing_notify_subs', 's', $qb->expr()->eq('q.subscription_id', 's.id'))
			->where($qb->expr()->eq('q.status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lte('q.available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('s.email_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('q.id')->setMaxResults(200)->executeQuery());
		$bySubscription = [];
		foreach ($rows as $row) $bySubscription[(int)$row['subscription_id']][] = (int)$row['id'];
		$sent = 0;
		foreach ($bySubscription as $subscriptionId => $ids) {
			$claimed = $this->claim($ids, $now);
			if ($claimed === []) continue;
			try {
				$this->sendDigest($subscriptionId, $claimed);
				$this->mark($claimed, 'sent', $now);
				$sent++;
			} catch (\Throwable) {
				$this->retry($claimed, $now);
			}
		}
		return $sent + $this->native->dispatchPending();
	}

	/** @return array<string, mixed>|null */
	private function findSubscription(int $galleryId, string $userUid): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_notify_subs')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	private function assertEligible(string $ownerUid, int $galleryId, string $recipientUid, bool $requiresEmail): void {
		$user = $this->users->get($recipientUid);
		if ($user === null) throw new InvalidArgumentException('Recipient must be a Nextcloud user');
		if ($requiresEmail && !$this->mailer->validateMailAddress((string)$user->getEMailAddress())) {
			throw new InvalidArgumentException('Recipient needs an email address for email notifications');
		}
		if ($recipientUid === $ownerUid) return;
		try {
			$this->managers->findPrincipal($galleryId, 'user', $recipientUid);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('Recipient must be the owner or an individual gallery manager');
		}
	}

	/** @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function present(array $row): array {
		$user = $this->users->get((string)$row['user_uid']);
		return [
			'id' => (int)$row['id'], 'galleryId' => (int)$row['gallery_id'], 'recipientUid' => $row['user_uid'],
			'recipientName' => $user?->getDisplayName() ?? $row['user_uid'],
			'eventTypes' => json_decode($row['event_types'], true, flags: JSON_THROW_ON_ERROR),
			'frequency' => $row['frequency'], 'locale' => $row['locale'], 'active' => (bool)$row['active'],
			'channels' => [
				'email' => [
					'enabled' => (bool)($row['email_enabled'] ?? true),
					'available' => $user !== null && $this->mailer->validateMailAddress((string)$user->getEMailAddress()),
					'eventTypes' => json_decode($row['event_types'], true, flags: JSON_THROW_ON_ERROR),
					'frequency' => $row['frequency'], 'locale' => $row['locale'],
				],
				'nextcloud' => [
					'enabled' => (bool)($row['native_enabled'] ?? false),
					'available' => $this->native->available((string)$row['user_uid']),
					'eventTypes' => json_decode((string)($row['native_event_types'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR),
				],
			],
		];
	}

	/** @param list<int> $ids
	 * @return list<int>
	 */
	private function claim(array $ids, int $now): array {
		$claimed = [];
		foreach ($ids as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('proofing_notify_queue')->set('status', $qb->createNamedParameter('sending'))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
			if ($qb->executeStatement() === 1) $claimed[] = $id;
		}
		return $claimed;
	}

	/** @param list<int> $queueIds */
	private function sendDigest(int $subscriptionId, array $queueIds): void {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('s.*')->from('proofing_notify_subs', 's')
			->where($qb->expr()->eq('s.id', $qb->createNamedParameter($subscriptionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('s.email_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery());
		if ($row === false) throw new \RuntimeException('Inactive subscription');
		$gallery = $this->galleries->find((int)$row['gallery_id']);
		$user = $this->users->get((string)$row['user_uid']);
		$email = $user?->getEMailAddress();
		if ($email === null || !$this->mailer->validateMailAddress($email)) throw new \RuntimeException('Recipient email unavailable');
		$eventsQb = $this->db->getQueryBuilder();
		$events = QueryResult::column($eventsQb->select('e.event_type')->from('proofing_notify_queue', 'q')
			->innerJoin('q', 'proofing_events', 'e', $eventsQb->expr()->eq('q.event_id', 'e.id'))
			->where($eventsQb->expr()->in('q.id', $eventsQb->createNamedParameter($queueIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery());
		$locale = (string)$row['locale'];
		if ($locale === 'auto') {
			$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$locale = $settings->publicLocale;
		}
		$l10n = $this->l10nFactory->get('proofing_gallery', $locale === 'auto' ? null : $locale);
		$template = $this->mailer->createEMailTemplate('proofing_gallery.digest', ['galleryId' => $gallery->getId()]);
		$template->setSubject($l10n->t('Gallery updates for “%s”', [$gallery->getTitle()]));
		$template->addHeader();
		$template->addHeading($gallery->getTitle());
		$template->addBodyText($this->digestText($events, $l10n));
		$template->addBodyButton($l10n->t('Open gallery activity'), $this->urlGenerator->linkToRouteAbsolute('proofing_gallery.page.index') . '#gallery/' . $gallery->getId() . '/activity');
		$template->addFooter($l10n->t('You subscribed to these gallery updates. Unsubscribe: %s', [
			$this->urlGenerator->linkToRouteAbsolute('proofing_gallery.notification.unsubscribe', ['token' => $row['unsubscribe_token']]),
		]));
		$mail = $this->mailer->createMessage()->setTo([$email])->useTemplate($template);
		if ($this->mailer->send($mail) !== []) throw new \RuntimeException('Notification delivery failed');
	}

	/** @param list<mixed> $events */
	private function digestText(array $events, \OCP\IL10N $l10n): string {
		$counts = array_count_values(array_map('strval', $events));
		$labels = [];
		foreach ($counts as $type => $count) {
			$label = match ($type) {
				'comment.created' => $l10n->t('New comments'),
				'comment.updated' => $l10n->t('Edited comments'),
				'selection.created' => $l10n->t('New selections'),
				'like.changed' => $l10n->t('Likes'),
				'color.changed' => $l10n->t('Color states'),
				'upload.received' => $l10n->t('New uploads'),
				'upload.accepted' => $l10n->t('Accepted uploads'),
				'upload.rejected' => $l10n->t('Rejected uploads'),
				default => $l10n->t('Gallery activity'),
			};
			$labels[] = sprintf('%d × %s', $count, $label);
		}
		return implode("\n", $labels);
	}

	/** @param list<int> $ids */
	private function mark(array $ids, string $status, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_notify_queue')->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
	}

	/** @param list<int> $ids */
	private function retry(array $ids, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_notify_queue')->set('status', $qb->createNamedParameter('pending'))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->set('available_at', $qb->createNamedParameter($now + 300, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
	}

	private function recoverStaleClaims(int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_notify_queue')->set('status', $qb->createNamedParameter('pending'))
			->where($qb->expr()->eq('status', $qb->createNamedParameter('sending')))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($now - 900, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
