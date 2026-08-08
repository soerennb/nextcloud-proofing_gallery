<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\ProcessPurgeRequestsJob;
use OCA\ProofingGallery\BackgroundJob\ContinuePurgeRequestsJob;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Db\PurgeRepository;
use OCA\ProofingGallery\Http\TemporaryFileResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IAppData;
use OCA\ProofingGallery\Db\ExternalResourceRepository;
use OCP\Talk\IBroker as TalkBroker;

final class PrivacyService {
	private const GRACE_SECONDS = 30 * 86400;
	private const BATCH_SIZE = 1000;

	public function __construct(
		private PurgeRepository $repository,
		private GalleryMapper $galleries,
		private PublicShareService $shares,
		private PublicLinkManagerService $publicLinks,
		private ITimeFactory $clock,
		private IJobList $jobs,
		private IAppData $appData,
		private \OCP\Security\ICrypto $crypto,
		private ExternalResourceRepository $externalResources,
		private TalkBroker $talk,
	) {
	}

	public function exportGuest(Guest $guest): TemporaryFileResponse {
		$path = tempnam(sys_get_temp_dir(), 'proofing-guest-export-');
		if ($path === false) throw new \RuntimeException('Temporary export could not be created');
		$stream = fopen($path, 'wb');
		if ($stream === false) throw new \RuntimeException('Temporary export could not be opened');
		try {
			$identity = $guest->jsonSerialize();
			$identity['email'] = $guest->getEmailCipher() === null ? null : $this->crypto->decrypt($guest->getEmailCipher());
			fwrite($stream, json_encode(['type' => 'guest', 'data' => $identity], JSON_THROW_ON_ERROR) . "\n");
			foreach ($this->repository->guestData((int)$guest->getId()) as $table => $rows) {
				foreach ($rows as $row) fwrite($stream, json_encode(['type' => $table, 'data' => $row], JSON_THROW_ON_ERROR) . "\n");
			}
		} finally { fclose($stream); }
		return new TemporaryFileResponse($path, 'proofing-gallery-my-data.ndjson', 'application/x-ndjson; charset=utf-8');
	}

	public function deleteGuest(Guest $guest): int {
		foreach ($this->repository->guestUploadIds((int)$guest->getId()) as $uploadId) {
			try { $this->appData->getFolder('guest-uploads')->getFolder($uploadId)->delete(); } catch (\Throwable) {}
		}
		return $this->repository->deleteGuestData((int)$guest->getId());
	}

	/** @return array<string, mixed> */
	public function preview(Gallery $gallery): array {
		$tables = $this->repository->counts((int)$gallery->getId());
		$categories = [
			'feedback' => $this->sum($tables, ['proofing_feedback', 'proofing_comments', 'proofing_annotations', 'proofing_selections', 'proofing_selection_items', 'proofing_guest_ratings', 'proofing_review_rounds']),
			'access' => $this->sum($tables, ['proofing_public_links', 'proofing_share_audit', 'proofing_domains', 'proofing_guests', 'proofing_managers']),
			'operations' => $this->sum($tables, ['proofing_events', 'proofing_uploads', 'proofing_notify_queue', 'proofing_native_notify', 'proofing_notify_subs', 'proofing_int_outbox', 'proofing_live_push', 'proofing_retention_log']),
			'processing' => $this->sum($tables, ['proofing_media_index', 'proofing_media_scan_queue', 'proofing_media_scans', 'proofing_semantic_idx', 'proofing_versions', 'proofing_ext_resources', 'proofing_summaries']),
		];
		return [
			'galleryId' => (int)$gallery->getId(), 'title' => $gallery->getTitle(), 'categories' => $categories,
			'totalAppRows' => array_sum($tables), 'graceDays' => 30, 'originalFilesAffected' => false,
			'activeRequest' => $this->presentRequest($this->repository->active((int)$gallery->getId())),
		];
	}

	/** @return array<string, mixed> */
	public function schedule(Gallery $gallery, string $requestedBy): array {
		if ($gallery->getOwnerUid() !== $requestedBy) throw new \InvalidArgumentException('Only the gallery owner can schedule a purge');
		if ($gallery->getStatus() !== 'archived') throw new \InvalidArgumentException('Archive the gallery before scheduling deletion');
		if ($this->repository->active((int)$gallery->getId()) !== null) throw new \InvalidArgumentException('A purge is already scheduled');
		$now = $this->clock->getTime();
		$snapshot = $this->preview($gallery);
		$this->repository->create((int)$gallery->getId(), $gallery->getTitle(), $requestedBy, $snapshot, $now, $now + self::GRACE_SECONDS);
		$this->jobs->add(ProcessPurgeRequestsJob::class);
		return $this->presentRequest($this->repository->active((int)$gallery->getId())) ?? [];
	}

	public function cancel(Gallery $gallery, int $requestId, string $userId): void {
		if ($gallery->getOwnerUid() !== $userId || !$this->repository->cancel($requestId, (int)$gallery->getId(), $this->clock->getTime())) {
			throw new \InvalidArgumentException('Scheduled purge could not be cancelled');
		}
	}

	public function export(Gallery $gallery): TemporaryFileResponse {
		$path = tempnam(sys_get_temp_dir(), 'proofing-gallery-export-');
		if ($path === false) throw new \RuntimeException('Temporary export could not be created');
		$stream = fopen($path, 'wb');
		if ($stream === false) throw new \RuntimeException('Temporary export could not be opened');
		try {
			$galleryData = $gallery->jsonSerialize();
			unset($galleryData['shareToken']);
			fwrite($stream, json_encode(['type' => 'gallery', 'data' => $galleryData], JSON_THROW_ON_ERROR) . "\n");
			foreach (PurgeRepository::TABLES as $table) {
				$afterId = 0;
				do {
					$rows = $this->repository->exportRows($table, (int)$gallery->getId(), $afterId, self::BATCH_SIZE);
					foreach ($rows as $row) {
						$afterId = max($afterId, (int)($row['__cursor'] ?? $row['id'] ?? 0));
						unset($row['__cursor'], $row['session_hash'], $row['nonce_hash'], $row['email_cipher'], $row['secret_hash'], $row['token'], $row['verification_token'], $row['unsubscribe_token']);
						fwrite($stream, json_encode(['type' => $table, 'data' => $row], JSON_THROW_ON_ERROR) . "\n");
					}
				} while (count($rows) === self::BATCH_SIZE);
			}
		} finally {
			fclose($stream);
		}
		return new TemporaryFileResponse($path, 'proofing-gallery-' . $gallery->getId() . '-export.ndjson', 'application/x-ndjson; charset=utf-8');
	}

	/** @return array{processed:int,remaining:int} */
	public function processDue(): array {
		$processed = 0;
		foreach ($this->repository->due($this->clock->getTime(), 20) as $request) {
			$this->processRequest($request);
			$processed++;
		}
		return ['processed' => $processed, 'remaining' => $processed === 20 ? 1 : 0];
	}

	/** Called by Nextcloud principal deletion listeners. */
	public function principalDeleted(string $type, string $id): void {
		$this->repository->deletePrincipal($type, $id);
		if ($type !== 'user') return;
		foreach ($this->repository->ownedGalleryIds($id) as $galleryId) {
			try {
				$now = $this->clock->getTime();
				$gallery = $this->galleries->find($galleryId);
				$this->revokeAccess($gallery, 'system:user-deleted');
				$gallery->setStatus('archived');
				$gallery->setArchivedAt($now);
				$this->galleries->update($gallery);
				if ($this->repository->active($galleryId) === null) {
					$this->repository->create($galleryId, $gallery->getTitle(), 'system:user-deleted', $this->preview($gallery), $now, $now + self::GRACE_SECONDS);
				}
			} catch (\Throwable) {
			}
		}
		$this->jobs->add(ProcessPurgeRequestsJob::class);
	}

	/** @param array<string, mixed> $request */
	private function processRequest(array $request): void {
		$galleryId = (int)$request['gallery_id'];
		$stage = (int)$request['stage'];
		$progress = json_decode((string)$request['progress'], true);
		if (!is_array($progress)) $progress = [];
		if ($stage === 0) {
			$this->deleteTalkRooms($galleryId);
			try {
				$gallery = $this->galleries->find($galleryId);
				$this->revokeAccess($gallery, (string)$request['requested_by']);
			} catch (\Throwable) {
				// Missing native shares are safe; local rows are removed in later stages.
			}
			$this->deleteUploadChunks($galleryId);
			$stage = 1;
		}
		$tableIndex = $stage - 1;
		if (!isset(PurgeRepository::TABLES[$tableIndex])) {
			$this->repository->advance((int)$request['id'], $stage, $progress, $this->clock->getTime(), true);
			return;
		}
		$table = PurgeRepository::TABLES[$tableIndex];
		$deleted = $this->repository->deleteBatch($table, $galleryId, self::BATCH_SIZE);
		$progress[$table] = (int)($progress[$table] ?? 0) + $deleted;
		if ($deleted < self::BATCH_SIZE) $stage++;
		$complete = $stage - 1 >= count(PurgeRepository::TABLES);
		$this->repository->advance((int)$request['id'], $stage, $progress, $this->clock->getTime(), $complete);
		if (!$complete) $this->jobs->add(ContinuePurgeRequestsJob::class);
	}

	private function deleteTalkRooms(int $galleryId): void {
		$resources = $this->externalResources->forGalleryProvider($galleryId, 'talk');
		if ($resources === []) return;
		if (!$this->talk->hasBackend()) throw new \RuntimeException('Talk backend is unavailable for review-room cleanup');
		foreach ($resources as $resource) {
			$remote = json_decode((string)$resource['remote_data'], true, flags: JSON_THROW_ON_ERROR);
			$conversationId = is_string($remote['conversationId'] ?? null) ? $remote['conversationId'] : '';
			if ($conversationId !== '') $this->talk->deleteConversation($conversationId);
			$this->externalResources->delete($galleryId, (int)$resource['public_link_id'], (string)$resource['user_uid'], 'talk');
		}
	}

	private function revokeAccess(Gallery $gallery, string $actor): void {
		$links = $this->publicLinks->list($gallery)['items'];
		foreach ($links as $link) if (($link['status'] ?? '') === 'active' && !($link['primary'] ?? false)) $this->publicLinks->revoke($gallery, (int)$link['id'], $actor);
		if ($gallery->getShareToken() !== null) $this->shares->revoke($gallery);
	}

	private function deleteUploadChunks(int $galleryId): void {
		foreach ($this->repository->uploadIds($galleryId) as $uploadId) {
			try { $this->appData->getFolder('guest-uploads')->getFolder($uploadId)->delete(); } catch (\Throwable) {}
		}
		try { $this->appData->getFolder('versions')->getFolder((string)$galleryId)->delete(); } catch (\Throwable) {}
	}

	/** @param array<string, int> $counts
	 * @param list<string> $tables */
	private function sum(array $counts, array $tables): int {
		return array_sum(array_map(static fn (string $table): int => (int)($counts[$table] ?? 0), $tables));
	}

	/** @param array<string, mixed>|null $request
	 * @return array<string, mixed>|null */
	private function presentRequest(?array $request): ?array {
		if ($request === null) return null;
		return [
			'id' => (int)$request['id'], 'status' => (string)$request['status'], 'executeAfter' => (int)$request['execute_after'],
			'createdAt' => (int)$request['created_at'], 'stage' => (int)$request['stage'],
		];
	}
}
