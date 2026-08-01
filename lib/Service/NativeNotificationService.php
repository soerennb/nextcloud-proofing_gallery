<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

final class NativeNotificationService {
	public const EVENT_TYPES = ['comment.created', 'selection.created', 'upload.received'];

	public function __construct(
		private IDBConnection $db,
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
		$category = $this->category($eventType);
		if ($category === null) return;
		$this->signalCategory($galleryId, $userUid, $category, $eventId);
	}

	public function signalCategory(int $galleryId, string $userUid, string $category, ?int $eventId = null): void {
		if (!in_array($category, ['comment', 'selection', 'upload', 'manager', 'revoked'], true)
			|| !$this->available($userUid)) return;

		$now = $this->clock->getTime();
		$row = $this->find($galleryId, $userUid, $category);
		$dispatch = false;
		if ($row === null) {
			$qb = $this->db->getQueryBuilder();
			try {
				$qb->insert('proofing_native_notify')->values([
					'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
					'user_uid' => $qb->createNamedParameter($userUid),
					'category' => $qb->createNamedParameter($category),
					'event_count' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'latest_event_id' => $eventId === null
						? $qb->createNamedParameter(null)
						: $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
					'status' => $qb->createNamedParameter('pending'),
					'active' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
					'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'available_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
				$row = $this->find($galleryId, $userUid, $category);
				$dispatch = true;
			} catch (Exception) {
				$row = $this->find($galleryId, $userUid, $category);
			}
		}
		if ($row === null) return;

		if (!$dispatch) {
			$wasActive = (bool)$row['active'];
			$retryFailed = $wasActive && (string)$row['status'] === 'failed';
			$qb = $this->db->getQueryBuilder();
			$qb->update('proofing_native_notify')
				->set('event_count', $wasActive ? $qb->createFunction('event_count + 1') : $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->set('latest_event_id', $eventId === null
					? $qb->createNamedParameter(null)
					: $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT))
				->set('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set('status', $qb->createNamedParameter($wasActive && !$retryFailed ? (string)$row['status'] : 'pending'))
				->set('attempts', $qb->createNamedParameter($wasActive && !$retryFailed ? (int)$row['attempts'] : 0, IQueryBuilder::PARAM_INT))
				->set('available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$dispatch = !$wasActive || $retryFailed;
		}

		if ($dispatch) $this->dispatchState((int)$row['id']);
	}

	public function dispatchPending(): int {
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$ids = $qb->select('id')->from('proofing_native_notify')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('available_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('id')->setMaxResults(100)->executeQuery()->fetchFirstColumn();
		$sent = 0;
		foreach ($ids as $id) if ($this->dispatchState((int)$id)) $sent++;
		return $sent;
	}

	/** @return array<string, mixed>|null */
	public function state(int $id, string $userUid): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('proofing_native_notify')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	public function dismiss(int $id, string $userUid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_native_notify')
			->set('active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('event_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->executeStatement();
	}

	public function processGalleryUser(int $galleryId, string $userUid): void {
		$qb = $this->db->getQueryBuilder();
		$ids = array_map('intval', $qb->select('id')->from('proofing_native_notify')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->executeQuery()->fetchFirstColumn());
		foreach ($ids as $id) {
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
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_native_notify')->set('status', $qb->createNamedParameter('sending'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		if ($qb->executeStatement() !== 1) return false;
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('proofing_native_notify')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAssociative();
		if ($row === false) return false;
		try {
			$notification = $this->manager->createNotification()
				->setApp(Application::APP_ID)
				->setUser((string)$row['user_uid'])
				->setDateTime(new \DateTime('@' . (int)$row['created_at']))
				->setObject('proofing_gallery_attention', (string)$id)
				->setSubject((string)$row['category']);
			$this->manager->notify($notification);
			$qb = $this->db->getQueryBuilder();
			$qb->update('proofing_native_notify')->set('status', $qb->createNamedParameter('delivered'))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			return true;
		} catch (\Throwable $exception) {
			$this->logger->warning('Native gallery notification delivery failed', ['exception' => $exception, 'stateId' => $id]);
			$attempts = (int)$row['attempts'] + 1;
			$qb = $this->db->getQueryBuilder();
			$qb->update('proofing_native_notify')->set('status', $qb->createNamedParameter($attempts >= 5 ? 'failed' : 'pending'))
				->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
				->set('available_at', $qb->createNamedParameter($now + min(3600, 300 * (2 ** max(0, $attempts - 1))), IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			return false;
		}
	}

	/** @return array<string, mixed>|null */
	private function find(int $galleryId, string $userUid, string $category): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('proofing_native_notify')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)))
			->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function category(string $eventType): ?string {
		return match ($eventType) {
			'comment.created' => 'comment',
			'selection.created' => 'selection',
			'upload.received' => 'upload',
			default => null,
		};
	}
}
