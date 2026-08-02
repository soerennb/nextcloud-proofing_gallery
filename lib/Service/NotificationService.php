<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\ManagerMapper;
use OCA\ProofingGallery\Db\NotificationRepository;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Activity\IManager as ActivityManager;
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
		private NotificationRepository $repository,
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
		return array_map(fn (array $row): array => $this->present($row), $this->repository->subscriptions($galleryId));
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
		$existing = $this->repository->saveSubscription(
			$galleryId,
			$recipientUid,
			$eventTypes,
			$emailEnabled,
			$nativeEnabled,
			$nativeEventTypes,
			$frequency,
			$locale,
			$this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC),
			$this->clock->getTime(),
		);
		if (!$nativeEnabled) $this->native->processGalleryUser($galleryId, $recipientUid);
		return $this->present($existing);
	}

	public function delete(string $ownerUid, int $galleryId, int $id): void {
		$this->access->owner($ownerUid, $galleryId);
		$userUid = $this->repository->deleteSubscription($galleryId, $id);
		if ($userUid === null) throw new DoesNotExistException('Notification subscription not found');
		$this->native->processGalleryUser($galleryId, $userUid);
	}

	public function unsubscribe(string $token): bool {
		if (!preg_match('/^[A-Za-z0-9]{48}$/', $token)) return false;
		return $this->repository->unsubscribe($token, $this->clock->getTime());
	}

	public function queue(Gallery $gallery, int $eventId, string $eventType, int $createdAt): void {
		if (!in_array($eventType, self::EVENT_TYPES, true)) return;
		$rows = $this->repository->activeSubscriptions($gallery->getId());
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
			$this->repository->enqueue((int)$row['id'], $eventId, $availableAt, $createdAt);
		}
	}

	public function dispatch(): int {
		$now = $this->clock->getTime();
		$sent = 0;
		foreach ($this->repository->pendingBySubscription($now) as $subscriptionId => $ids) {
			$claimed = $this->repository->claim($ids, $now);
			if ($claimed === []) continue;
			try {
				$this->sendDigest($subscriptionId, $claimed);
				$this->repository->markSent($claimed, $now);
				$sent++;
			} catch (\Throwable) {
				$this->repository->retry($claimed, $now);
			}
		}
		return $sent + $this->native->dispatchPending();
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

	/** @param list<int> $queueIds */
	private function sendDigest(int $subscriptionId, array $queueIds): void {
		$row = $this->repository->activeEmailSubscription($subscriptionId);
		if ($row === null) throw new \RuntimeException('Inactive subscription');
		$gallery = $this->galleries->find((int)$row['gallery_id']);
		$user = $this->users->get((string)$row['user_uid']);
		$email = $user?->getEMailAddress();
		if ($email === null || !$this->mailer->validateMailAddress($email)) throw new \RuntimeException('Recipient email unavailable');
		$events = $this->repository->eventTypes($queueIds);
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

}
