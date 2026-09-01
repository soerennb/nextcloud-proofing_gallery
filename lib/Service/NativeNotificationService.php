<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\NativeNotificationRepository;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

final class NativeNotificationService {
	public const EVENT_TYPES = ['comment.created', 'selection.created', 'upload.received', 'review.submitted'];

	public function __construct(
		private NativeNotificationRepository $repository,
		private ITimeFactory $clock,
		private IManager $manager,
		private IAppManager $apps,
		private IUserManager $users,
		private PolicyService $policies,
		private LoggerInterface $logger,
	) {
	}

	public function available(string $userUid): bool {
		$user = $this->users->get($userUid);
		return $this->policies->feature('nextcloudNotifications')
			&& $user !== null
			&& $this->apps->isEnabledForUser('notifications', $user);
	}

	public function signal(int $galleryId, string $userUid, int $eventId, string $eventType): void {
		$stateId = $this->stageSignal($galleryId, $userUid, $eventId, $eventType);
		if ($stateId !== null) $this->publish($stateId);
	}

	public function stageSignal(int $galleryId, string $userUid, int $eventId, string $eventType): ?int {
		$category = $this->category($eventType);
		if ($category === null || !$this->available($userUid)) return null;
		$state = $this->repository->signal($galleryId, $userUid, $category, $eventId, $this->clock->getTime());
		return $state !== null && $state['dispatch'] ? $state['id'] : null;
	}

	public function publish(int $stateId): void {
		try {
			$this->dispatchState($stateId);
		} catch (\Throwable $exception) {
			$this->logger->warning('Native gallery notification dispatch failed', ['exception' => $exception, 'stateId' => $stateId]);
		}
	}

	public function signalCategory(int $galleryId, string $userUid, string $category, ?int $eventId = null): void {
		if (!in_array($category, ['comment', 'selection', 'upload', 'review', 'manager', 'lifecycle', 'revoked'], true)
			|| !$this->available($userUid)) return;

		$state = $this->repository->signal($galleryId, $userUid, $category, $eventId, $this->clock->getTime());
		if ($state !== null && $state['dispatch']) $this->dispatchState($state['id']);
	}

	public function dispatchPending(): int {
		$sent = 0;
		foreach ($this->repository->pendingIds($this->clock->getTime()) as $id) if ($this->dispatchState($id)) $sent++;
		return $sent;
	}

	/** @return array<string, mixed>|null */
	public function state(int $id, string $userUid): ?array {
		return $this->repository->activeState($id, $userUid);
	}

	public function dismiss(int $id, string $userUid): void {
		$this->repository->dismiss($id, $userUid, $this->clock->getTime());
	}

	public function processGalleryUser(int $galleryId, string $userUid): void {
		foreach ($this->repository->activeIds($galleryId, $userUid) as $id) {
			try {
				$notification = $this->manager->createNotification()
					->setApp(Application::APP_ID)->setUser($userUid)
					->setObject('proofing_gallery_attention', (string)$id);
				$this->manager->markProcessed($notification);
			} catch (\Throwable $exception) {
				$this->logger->warning('Native gallery notification cleanup failed', ['exception' => $exception, 'stateId' => $id]);
			} finally {
				$this->dismiss($id, $userUid);
			}
		}
	}

	private function dispatchState(int $id): bool {
		$now = $this->clock->getTime();
		$row = $this->repository->claim($id, $now);
		if ($row === null) return false;
		try {
			$notification = $this->manager->createNotification()
				->setApp(Application::APP_ID)
				->setUser((string)$row['user_uid'])
				->setDateTime(new \DateTime('@' . (int)$row['created_at']))
				->setObject('proofing_gallery_attention', (string)$id)
				->setSubject((string)$row['category']);
			$this->manager->notify($notification);
			$this->repository->markDelivered($id, $now);
			return true;
		} catch (\Throwable $exception) {
			$this->logger->warning('Native gallery notification delivery failed', ['exception' => $exception, 'stateId' => $id]);
			$attempts = (int)$row['attempts'] + 1;
			$this->repository->markFailedAttempt($id, $attempts, $now);
			return false;
		}
	}

	private function category(string $eventType): ?string {
		return match ($eventType) {
			'comment.created' => 'comment',
			'selection.created' => 'selection',
			'upload.received' => 'upload',
			'review.submitted' => 'review',
			default => null,
		};
	}
}
