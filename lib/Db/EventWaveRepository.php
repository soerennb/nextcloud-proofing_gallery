<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class EventWaveRepository {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @param array{sharedRoots: list<array{folderId: int, path: string}>, policy: array<string, mixed>, expiresAt: ?string, releaseAt: ?int, sendInvitations: bool} $wave
	 * @param list<array{folderId: int, folderPath: string, groupRoots: list<array{folderId: int, path: string, name: string}>, name: string, emailCipher: ?string, locale: ?string, pinCipher: ?string}> $recipients
	 */
	public function create(int $galleryId, string $status, array $wave, array $recipients, int $now, ?string $requestKey = null): int {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('proofing_event_waves')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'request_key' => $qb->createNamedParameter($requestKey),
				'status' => $qb->createNamedParameter($status),
				'shared_roots' => $qb->createNamedParameter(json_encode(array_column($wave['sharedRoots'], 'path'), JSON_THROW_ON_ERROR)),
				'policy' => $qb->createNamedParameter(json_encode($wave['policy'], JSON_THROW_ON_ERROR)),
				'expires_at' => $qb->createNamedParameter($wave['expiresAt']),
				'release_at' => $qb->createNamedParameter($wave['releaseAt'], IQueryBuilder::PARAM_INT),
				'send_invitations' => $qb->createNamedParameter($wave['sendInvitations'], IQueryBuilder::PARAM_BOOL),
				'total_count' => $qb->createNamedParameter(count($recipients), IQueryBuilder::PARAM_INT),
				'processed_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'failed_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			$waveId = (int)$this->db->lastInsertId('proofing_event_waves');
			foreach ($wave['sharedRoots'] as $sharedRoot) $this->insertRoot($waveId, null, $sharedRoot['folderId'], $sharedRoot['path'], 'shared', null, $now);
			foreach ($recipients as $recipient) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('proofing_event_recipients')->values([
					'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
					'public_link_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
					'folder_id' => $qb->createNamedParameter($recipient['folderId'], IQueryBuilder::PARAM_INT),
					'folder_path' => $qb->createNamedParameter($recipient['folderPath']),
					'group_roots' => $qb->createNamedParameter(json_encode(array_column($recipient['groupRoots'], 'path'), JSON_THROW_ON_ERROR)),
					'display_name' => $qb->createNamedParameter($recipient['name']),
					'email_cipher' => $qb->createNamedParameter($recipient['emailCipher']),
					'locale' => $qb->createNamedParameter($recipient['locale']),
					'delivery_status' => $qb->createNamedParameter('draft'),
					'invited_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
					'wave_id' => $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT),
					'pin_cipher' => $qb->createNamedParameter($recipient['pinCipher']),
					'publication_status' => $qb->createNamedParameter('draft'),
					'invitation_status' => $qb->createNamedParameter($wave['sendInvitations'] ? 'pending' : 'not_requested'),
					'error_code' => $qb->createNamedParameter(null),
					'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
				$recipientId = (int)$this->db->lastInsertId('proofing_event_recipients');
				$this->insertRoot($waveId, $recipientId, $recipient['folderId'], $recipient['folderPath'], 'private', null, $now);
				foreach ($recipient['groupRoots'] as $groupRoot) $this->insertRoot($waveId, $recipientId, $groupRoot['folderId'], $groupRoot['path'], 'group', $groupRoot['name'], $now);
			}
			$this->db->commit();
			return $waveId;
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}

	/** @return array<string, mixed>|false */
	public function findByRequestKey(int $galleryId, string $requestKey): array|false {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::row($qb->select('*')->from('proofing_event_waves')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('request_key', $qb->createNamedParameter($requestKey)))->executeQuery());
	}

	/** @return list<array<string, mixed>> */
	public function roots(int $waveId, ?int $recipientId, string $role): array {
		$qb = $this->db->getQueryBuilder();
		$query = $qb->select('*')->from('proofing_event_roots')
			->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role)));
		$query->andWhere($recipientId === null ? $qb->expr()->isNull('recipient_id') : $qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)));
		return QueryResult::rows($query->orderBy('id', 'ASC')->executeQuery());
	}

	private function insertRoot(int $waveId, ?int $recipientId, int $folderId, string $path, string $role, ?string $groupName, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_event_roots')->values([
			'wave_id' => $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT), 'recipient_id' => $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT),
			'folder_id' => $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT), 'path_snapshot' => $qb->createNamedParameter($path),
			'role' => $qb->createNamedParameter($role), 'group_name' => $qb->createNamedParameter($groupName),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return array<string, mixed>|false */
	public function find(int $waveId): array|false {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::row($qb->select('*')->from('proofing_event_waves')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))->executeQuery());
	}

	/** @return list<array<string, mixed>> */
	public function gallery(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_event_waves')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->executeQuery());
	}

	public function reserved(int $galleryId): int {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count())->from('proofing_event_recipients', 'r')
			->innerJoin('r', 'proofing_event_waves', 'w', $qb->expr()->eq('w.id', 'r.wave_id'))
			->where($qb->expr()->eq('r.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('w.status', $qb->createNamedParameter(['draft', 'scheduled', 'releasing'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->neq('r.publication_status', $qb->createNamedParameter('published')))->executeQuery()->fetchOne();
	}

	/** @return list<array<string, mixed>> */
	public function claim(int $waveId, int $limit, int $now): array {
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('*')->from('proofing_event_recipients')
			->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('publication_status', $qb->createNamedParameter(['draft', 'failed'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery());
		$claimed = [];
		foreach ($rows as $row) {
			$qb = $this->db->getQueryBuilder();
			$updated = $qb->update('proofing_event_recipients')->set('publication_status', $qb->createNamedParameter('publishing'))
				->set('attempts', $qb->createFunction('attempts + 1'))->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('publication_status', $qb->createNamedParameter(['draft', 'failed'], IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
			if ($updated === 1) $claimed[] = $row;
		}
		return $claimed;
	}

	public function published(int $recipientId, int $linkId, string $invitationStatus, ?int $invitedAt, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('public_link_id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT))
			->set('publication_status', $qb->createNamedParameter('published'))->set('delivery_status', $qb->createNamedParameter($invitedAt === null ? 'published' : 'invited'))
			->set('invitation_status', $qb->createNamedParameter($invitationStatus))->set('invited_at', $qb->createNamedParameter($invitedAt, IQueryBuilder::PARAM_INT))
			->set('error_code', $qb->createNamedParameter(null))->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function failed(int $recipientId, string $code, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('publication_status', $qb->createNamedParameter('failed'))
			->set('delivery_status', $qb->createNamedParameter('failed'))->set('error_code', $qb->createNamedParameter($code))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @return array{processed: int, failed: int, pending: int} */
	public function progress(int $waveId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('publication_status', $qb->func()->count('*', 'amount'))->from('proofing_event_recipients')
			->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
			->groupBy('publication_status')->executeQuery());
		$counts = [];
		foreach ($rows as $row) $counts[(string)$row['publication_status']] = (int)$row['amount'];
		return ['processed' => ($counts['published'] ?? 0) + ($counts['failed'] ?? 0), 'failed' => $counts['failed'] ?? 0, 'pending' => ($counts['draft'] ?? 0) + ($counts['publishing'] ?? 0)];
	}

	public function updateWave(int $waveId, string $status, ?int $releaseAt, int $processed, int $failed, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_waves')->set('status', $qb->createNamedParameter($status))
			->set('release_at', $qb->createNamedParameter($releaseAt, IQueryBuilder::PARAM_INT))
			->set('processed_count', $qb->createNamedParameter($processed, IQueryBuilder::PARAM_INT))
			->set('failed_count', $qb->createNamedParameter($failed, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function retryFailed(int $waveId, int $now): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_event_recipients')->set('publication_status', $qb->createNamedParameter('draft'))
			->set('delivery_status', $qb->createNamedParameter('draft'))->set('error_code', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('publication_status', $qb->createNamedParameter('failed')))->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function recipients(int $waveId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_event_recipients')
			->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->executeQuery());
	}

	/** @return array<string, mixed>|false */
	public function recipient(int $galleryId, int $recipientId): array|false {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::row($qb->select('*')->from('proofing_event_recipients')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeQuery());
	}

	/** @return array{items: list<array<string, mixed>>, total: int, nextId: ?int} */
	public function recipientPage(int $galleryId, int $limit, ?int $beforeId, ?string $status, string $query): array {
		$limit = max(1, min(100, $limit));
		$build = function () use ($galleryId, $beforeId, $status, $query): IQueryBuilder {
			$qb = $this->db->getQueryBuilder();
			$qb->from('proofing_event_recipients')->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
			if ($beforeId !== null) $qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
			if ($status !== null && $status !== '') $qb->andWhere($qb->expr()->eq('delivery_status', $qb->createNamedParameter($status)));
			if ($query !== '') {
				$like = '%' . $this->db->escapeLikeParameter(mb_strtolower($query)) . '%';
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->iLike('display_name', $qb->createNamedParameter($like)),
					$qb->expr()->iLike('folder_path', $qb->createNamedParameter($like)),
				));
			}
			return $qb;
		};
		$rows = QueryResult::rows($build()->select('*')->orderBy('id', 'DESC')->setMaxResults($limit + 1)->executeQuery());
		$hasMore = count($rows) > $limit;
		if ($hasMore) array_pop($rows);
		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count())->from('proofing_event_recipients')->where($count->expr()->eq('gallery_id', $count->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		if ($status !== null && $status !== '') $count->andWhere($count->expr()->eq('delivery_status', $count->createNamedParameter($status)));
		if ($query !== '') {
			$like = '%' . $this->db->escapeLikeParameter(mb_strtolower($query)) . '%';
			$count->andWhere($count->expr()->orX($count->expr()->iLike('display_name', $count->createNamedParameter($like)), $count->expr()->iLike('folder_path', $count->createNamedParameter($like))));
		}
		$total = (int)$count->executeQuery()->fetchOne();
		$last = $rows === [] ? null : $rows[array_key_last($rows)];
		return ['items' => $rows, 'total' => $total, 'nextId' => $hasMore && $last !== null ? (int)$last['id'] : null];
	}

	/** @param list<array{folderId: int, path: string, name: string}> $groupRoots */
	public function updateRecipient(int $waveId, int $recipientId, int $folderId, string $folderPath, array $groupRoots, string $name, ?string $emailCipher, ?string $locale, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
			->set('folder_path', $qb->createNamedParameter($folderPath))->set('group_roots', $qb->createNamedParameter(json_encode(array_column($groupRoots, 'path'), JSON_THROW_ON_ERROR)))
			->set('display_name', $qb->createNamedParameter($name))->set('email_cipher', $qb->createNamedParameter($emailCipher))->set('locale', $qb->createNamedParameter($locale))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
		$delete = $this->db->getQueryBuilder();
		$delete->delete('proofing_event_roots')->where($delete->expr()->eq('recipient_id', $delete->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
		$this->insertRoot($waveId, $recipientId, $folderId, $folderPath, 'private', null, $now);
		foreach ($groupRoots as $groupRoot) $this->insertRoot($waveId, $recipientId, $groupRoot['folderId'], $groupRoot['path'], 'group', $groupRoot['name'], $now);
	}

	public function replaceLink(int $recipientId, int $linkId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('public_link_id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT))
			->set('publication_status', $qb->createNamedParameter('published'))->set('delivery_status', $qb->createNamedParameter('published'))
			->set('error_code', $qb->createNamedParameter(null))->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function revokeRecipient(int $recipientId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('delivery_status', $qb->createNamedParameter('revoked'))
			->set('publication_status', $qb->createNamedParameter('revoked'))->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function reconcileRecipient(int $recipientId, string $deliveryStatus, string $publicationStatus, ?string $errorCode, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('delivery_status', $qb->createNamedParameter($deliveryStatus))
			->set('publication_status', $qb->createNamedParameter($publicationStatus))->set('error_code', $qb->createNamedParameter($errorCode))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function deleteRecipient(int $recipientId): void {
		$lookup = $this->db->getQueryBuilder();
		$waveId = $lookup->select('wave_id')->from('proofing_event_recipients')->where($lookup->expr()->eq('id', $lookup->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
		$roots = $this->db->getQueryBuilder();
		$roots->delete('proofing_event_roots')->where($roots->expr()->eq('recipient_id', $roots->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_event_recipients')->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))->executeStatement();
		if ($waveId !== false && $waveId !== null) {
			$progress = $this->progress((int)$waveId);
			$count = $this->db->getQueryBuilder();
			$total = (int)$count->select($count->func()->count())->from('proofing_event_recipients')->where($count->expr()->eq('wave_id', $count->createNamedParameter((int)$waveId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
			$wave = $this->db->getQueryBuilder();
			$wave->update('proofing_event_waves')->set('total_count', $wave->createNamedParameter($total, IQueryBuilder::PARAM_INT))
				->set('processed_count', $wave->createNamedParameter($progress['processed'], IQueryBuilder::PARAM_INT))->set('failed_count', $wave->createNamedParameter($progress['failed'], IQueryBuilder::PARAM_INT))
				->where($wave->expr()->eq('id', $wave->createNamedParameter((int)$waveId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
	}

	public function saveHandoff(int $waveId, string $cipher, int $expiresAt, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_pin_handoffs')->values([
			'wave_id' => $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT), 'content_cipher' => $qb->createNamedParameter($cipher),
			'expires_at' => $qb->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT), 'consumed_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('pin_cipher', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function handoffAvailable(int $waveId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->select('id')->from('proofing_pin_handoffs')->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('consumed_at'))->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne() !== false;
	}

	public function handoffExists(int $waveId): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->select('id')->from('proofing_pin_handoffs')
			->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne() !== false;
	}

	public function consumeHandoff(int $waveId, int $now): ?string {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$row = QueryResult::row($qb->select('id', 'content_cipher')->from('proofing_pin_handoffs')
				->where($qb->expr()->eq('wave_id', $qb->createNamedParameter($waveId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->isNull('consumed_at'))->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))->executeQuery());
			if ($row === false) { $this->db->commit(); return null; }
			$qb = $this->db->getQueryBuilder();
			$changed = $qb->update('proofing_pin_handoffs')->set('consumed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->isNull('consumed_at'))->executeStatement();
			$this->db->commit();
			return $changed === 1 ? (string)$row['content_cipher'] : null;
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}
}
