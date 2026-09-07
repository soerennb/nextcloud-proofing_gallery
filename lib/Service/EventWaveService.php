<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\ProcessEventWaveJob;
use OCA\ProofingGallery\Db\EventWaveRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Security\ICrypto;

final class EventWaveService {
	private const BATCH_SIZE = 25;
	private const HANDOFF_TTL = 604800;

	public function __construct(
		private EventWaveRepository $repository,
		private GalleryMapper $galleries,
		private FolderService $folders,
		private PublicLinkManagerService $links,
		private PublicLinkPolicyService $policies,
		private InvitationService $invitations,
		private CsvEncoder $csv,
		private ITimeFactory $clock,
		private ICrypto $crypto,
		private IJobList $jobs,
	) {
	}

	/**
	 * @param list<string> $sharedRoots
	 * @param list<array<string, mixed>> $recipients
	 * @param array<string, mixed> $policy
	 * @return array<string, mixed>
	 */
	public function create(Gallery $gallery, array $sharedRoots, array $recipients, array $policy = [], ?string $expiresAt = null, ?int $releaseAt = null, bool $sendInvitations = false, bool $releaseNow = false, ?string $requestKey = null): array {
		$this->assertEventGallery($gallery);
		if ($requestKey !== null) {
			if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestKey) !== 1) throw new \InvalidArgumentException('Invalid event delivery request key');
			$existing = $this->repository->findByRequestKey((int)$gallery->getId(), $requestKey);
			if ($existing !== false) return $this->present($existing);
		}
		if ($recipients === []) throw new \InvalidArgumentException('Select at least one event recipient');
		if ($expiresAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt) !== 1) throw new \InvalidArgumentException('Invalid expiration date');
		$now = $this->clock->getTime();
		if ($releaseAt !== null && $releaseAt <= $now) $releaseNow = true;
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$sharedRoots = $this->folderRecords($root, $sharedRoots);
		$policy = $policy === [] ? $this->policies->forGallery($gallery) : $this->policies->validate($policy);
		$prepared = [];
		foreach ($recipients as $recipient) $prepared[] = $this->prepareRecipient($root, array_column($sharedRoots, 'path'), $recipient);
		$this->links->assertEventCapacity($gallery, count($prepared), $this->repository->reserved((int)$gallery->getId()));
		$status = $releaseNow ? 'releasing' : ($releaseAt === null ? 'draft' : 'scheduled');
		try {
			$waveId = $this->repository->create((int)$gallery->getId(), $status, [
				'sharedRoots' => $sharedRoots, 'policy' => $policy, 'expiresAt' => $expiresAt,
				'releaseAt' => $releaseAt, 'sendInvitations' => $sendInvitations,
			], $prepared, $now, $requestKey);
		} catch (\Throwable $exception) {
			// The unique request key is the final guard for concurrent retries. If another
			// request committed first, return its wave instead of exposing a duplicate error.
			$existing = $requestKey === null ? false : $this->repository->findByRequestKey((int)$gallery->getId(), $requestKey);
			if ($existing === false) throw $exception;
			return $this->present($existing);
		}
		if ($status === 'releasing') $this->queue($waveId);
		elseif ($status === 'scheduled') $this->queue($waveId, $releaseAt);
		return $this->show($gallery, $waveId);
	}

	/** @return list<array<string, mixed>> */
	public function list(Gallery $gallery): array {
		$this->assertEventGallery($gallery);
		return array_map(fn (array $row): array => $this->present($row), $this->repository->gallery((int)$gallery->getId()));
	}

	/** @return array{summary: array<string, int>, waves: list<array<string, mixed>>} */
	public function operations(Gallery $gallery): array {
		$this->assertEventGallery($gallery);
		return [
			'summary' => $this->repository->recipientSummary((int)$gallery->getId()),
			'waves' => $this->list($gallery),
		];
	}

	/** @return array<string, mixed> */
	public function show(Gallery $gallery, int $waveId): array {
		return $this->present($this->owned($gallery, $waveId));
	}

	/** @return array<string, mixed> */
	public function release(Gallery $gallery, int $waveId): array {
		$row = $this->owned($gallery, $waveId);
		if (!in_array($row['status'], ['draft', 'scheduled', 'partial_failed'], true)) throw new \InvalidArgumentException('This wave cannot be released');
		if ($row['status'] === 'partial_failed') $this->repository->retryFailed($waveId, $this->clock->getTime());
		$progress = $this->repository->progress($waveId);
		$this->repository->updateWave($waveId, 'releasing', null, $progress['processed'], $progress['failed'], $this->clock->getTime());
		$this->queue($waveId);
		return $this->show($gallery, $waveId);
	}

	/** @return array<string, mixed> */
	public function schedule(Gallery $gallery, int $waveId, int $releaseAt): array {
		$row = $this->owned($gallery, $waveId);
		if ($row['status'] !== 'draft') throw new \InvalidArgumentException('Only draft waves can be scheduled');
		if ($releaseAt <= $this->clock->getTime()) throw new \InvalidArgumentException('Release time must be in the future');
		$this->repository->updateWave($waveId, 'scheduled', $releaseAt, 0, 0, $this->clock->getTime());
		$this->queue($waveId, $releaseAt);
		return $this->show($gallery, $waveId);
	}

	/** @return array<string, mixed> */
	public function retry(Gallery $gallery, int $waveId): array {
		$row = $this->owned($gallery, $waveId);
		if ($row['status'] !== 'partial_failed') throw new \InvalidArgumentException('Only failed recipients can be retried');
		if ($this->repository->retryFailed($waveId, $this->clock->getTime()) === 0) throw new \InvalidArgumentException('This wave has no failed recipients');
		$progress = $this->repository->progress($waveId);
		$this->repository->updateWave($waveId, 'releasing', null, $progress['processed'], $progress['failed'], $this->clock->getTime());
		$this->queue($waveId);
		return $this->show($gallery, $waveId);
	}

	/** @return array<string, mixed> */
	public function cancel(Gallery $gallery, int $waveId): array {
		$row = $this->owned($gallery, $waveId);
		if (!in_array($row['status'], ['draft', 'scheduled'], true)) throw new \InvalidArgumentException('Only unreleased waves can be cancelled');
		$this->repository->updateWave($waveId, 'cancelled', null, 0, 0, $this->clock->getTime());
		return $this->show($gallery, $waveId);
	}

	public function process(int $waveId): void {
		$row = $this->repository->find($waveId);
		if ($row === false || !in_array($row['status'], ['scheduled', 'releasing'], true)) return;
		$now = $this->clock->getTime();
		if ($row['status'] === 'scheduled' && (int)$row['release_at'] > $now) { $this->queue($waveId, (int)$row['release_at']); return; }
		if ($row['status'] === 'scheduled') $this->repository->updateWave($waveId, 'releasing', null, 0, 0, $now);
		try { $gallery = $this->galleries->find((int)$row['gallery_id']); } catch (\Throwable) { return; }
		$sharedRoots = $this->resolvedRoots($gallery, (int)$row['id'], null, 'shared');
		$policy = $this->decodeMap((string)$row['policy']);
		try { $this->links->configureEventPrimary($gallery, $sharedRoots); } catch (\Throwable) {
			foreach ($this->repository->claim($waveId, self::BATCH_SIZE, $now) as $recipient) $this->repository->failed((int)$recipient['id'], 'master_scope_unavailable', $now);
			$this->finishOrContinue($gallery, $row);
			return;
		}
		foreach ($this->repository->claim($waveId, self::BATCH_SIZE, $now) as $recipient) $this->publish($gallery, $row, $recipient, $sharedRoots, $policy);
		$this->finishOrContinue($gallery, $row);
	}

	public function consumePinCsv(Gallery $gallery, int $waveId): string {
		$this->owned($gallery, $waveId);
		$cipher = $this->repository->consumeHandoff($waveId, $this->clock->getTime());
		if ($cipher === null) throw new \InvalidArgumentException('PIN export is unavailable or was already downloaded');
		return $this->crypto->decrypt($cipher);
	}

	/**
	 * @param array<string, mixed> $wave
	 * @param array<string, mixed> $recipient
	 * @param list<string> $sharedRoots
	 * @param array<string, mixed> $policy
	 */
	private function publish(Gallery $gallery, array $wave, array $recipient, array $sharedRoots, array $policy): void {
		$link = null;
		$now = $this->clock->getTime();
		try {
			$pin = $recipient['pin_cipher'] === null ? '' : $this->crypto->decrypt((string)$recipient['pin_cipher']);
			$privateRoot = $this->resolvedPrivateRoot($gallery, (int)$wave['id'], $recipient);
			$groupRoots = $this->resolvedRoots($gallery, (int)$wave['id'], (int)$recipient['id'], 'group');
			$link = $this->links->create($gallery, PublicLinkConfiguration::fromArray([
				'name' => (string)$recipient['display_name'], 'policy' => $policy, 'startPath' => '',
				'allowedRoots' => [...$sharedRoots, ...$groupRoots, $privateRoot], 'viewMode' => 'folder', 'groupDepth' => 1,
				'publicLocale' => $recipient['locale'], 'password' => $pin, 'expiresAt' => $wave['expires_at'],
				'reviewEnabled' => in_array($gallery->getPurpose(), ['selection', 'proofing'], true),
			]), true, $privateRoot, $groupRoots);
			$invitationStatus = (bool)$wave['send_invitations'] ? 'pending' : 'not_requested';
			$invitedAt = null;
			if ((bool)$wave['send_invitations'] && $recipient['email_cipher'] !== null) {
				try {
					$this->invitations->sendPublicLink($gallery, $this->crypto->decrypt((string)$recipient['email_cipher']), (string)$link['url'], is_string($recipient['locale']) ? $recipient['locale'] : null);
					$invitationStatus = 'sent'; $invitedAt = $now;
				} catch (\Throwable) { $invitationStatus = 'failed'; }
			}
			$this->repository->published((int)$recipient['id'], (int)$link['id'], $invitationStatus, $invitedAt, $now);
		} catch (\Throwable $exception) {
			if ($link !== null) try { $this->links->revoke($gallery, (int)$link['id'], $gallery->getOwnerUid()); } catch (\Throwable) {}
			$this->repository->failed((int)$recipient['id'], $this->failureCode($exception), $now);
		}
	}

	/** @param array<string, mixed> $wave */
	private function finishOrContinue(Gallery $gallery, array $wave): void {
		$waveId = (int)$wave['id']; $now = $this->clock->getTime(); $progress = $this->repository->progress($waveId);
		if ($progress['pending'] > 0) {
			$this->repository->updateWave($waveId, 'releasing', null, $progress['processed'], $progress['failed'], $now);
			$this->queue($waveId); return;
		}
		$status = $progress['failed'] > 0 ? 'partial_failed' : 'released';
		$this->repository->updateWave($waveId, $status, null, $progress['processed'], $progress['failed'], $now);
		$this->createHandoff($gallery, $waveId, $now);
	}

	private function createHandoff(Gallery $gallery, int $waveId, int $now): void {
		if ($this->repository->handoffExists($waveId)) return;
		$links = [];
		foreach ($this->links->list($gallery)['items'] as $link) $links[(int)$link['id']] = (string)$link['url'];
		$rows = [['Name', 'Folder', 'PIN', 'Link']]; $hasPins = false;
		foreach ($this->repository->recipients($waveId) as $recipient) {
			if ($recipient['pin_cipher'] === null || $recipient['public_link_id'] === null) continue;
			$hasPins = true;
			$rows[] = [(string)$recipient['display_name'], (string)$recipient['folder_path'], $this->crypto->decrypt((string)$recipient['pin_cipher']), $links[(int)$recipient['public_link_id']] ?? ''];
		}
		if ($hasPins) $this->repository->saveHandoff($waveId, $this->crypto->encrypt($this->csv->encode($rows)), $now + self::HANDOFF_TTL, $now);
	}

	/**
	 * @param list<string> $sharedRoots
	 * @param array<string, mixed> $recipient
	 * @return array{setupKey: ?string, folderId: int, folderPath: string, groupRoots: list<array{folderId: int, path: string, name: string}>, name: string, emailCipher: ?string, locale: ?string, pinCipher: ?string}
	 */
	private function prepareRecipient(Folder $root, array $sharedRoots, array $recipient): array {
		$setupKey = is_string($recipient['setupKey'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $recipient['setupKey']) === 1 ? $recipient['setupKey'] : null;
		$folderPath = is_string($recipient['folderPath'] ?? null) ? trim($recipient['folderPath'], '/') : '';
		$name = is_string($recipient['name'] ?? null) ? trim($recipient['name']) : '';
		$email = is_string($recipient['email'] ?? null) ? mb_strtolower(trim($recipient['email'])) : '';
		$locale = is_string($recipient['locale'] ?? null) && in_array($recipient['locale'], ['de', 'en'], true) ? $recipient['locale'] : null;
		$pin = is_string($recipient['pin'] ?? null) ? trim($recipient['pin']) : '';
		$groupRootPaths = is_array($recipient['groupRoots'] ?? null) ? $this->folderPaths($root, $recipient['groupRoots']) : [];
		$groupRoots = array_map(function (string $path) use ($root): array {
			$folder = $root->get($path);
			if (!$folder instanceof Folder) throw new \InvalidArgumentException('Event group folder not found');
			return ['folderId' => (int)$folder->getId(), 'path' => $path, 'name' => mb_substr($folder->getName(), 0, 120)];
		}, $groupRootPaths);
		if ($folderPath === '' || $name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Every recipient needs a folder and a name');
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Recipient email address is invalid');
		if ($pin !== '' && (mb_strlen($pin) < 10 || mb_strlen($pin) > 64)) throw new \InvalidArgumentException('Recipient PIN must contain 10 to 64 characters');
		$privatePath = $this->folderPaths($root, [$folderPath])[0];
		if (in_array($privatePath, $sharedRoots, true)) throw new \InvalidArgumentException('A private recipient folder cannot also be shared');
		if (array_intersect($groupRootPaths, $sharedRoots) !== [] || in_array($privatePath, $groupRootPaths, true)) throw new \InvalidArgumentException('Shared, group, and private folders must be distinct');
		$privateFolder = $root->get($privatePath);
		if (!$privateFolder instanceof Folder) throw new \InvalidArgumentException('Event folder not found');
		return ['setupKey' => $setupKey, 'folderId' => (int)$privateFolder->getId(), 'folderPath' => $privatePath, 'groupRoots' => $groupRoots, 'name' => $name,
			'emailCipher' => $email === '' ? null : $this->crypto->encrypt($email), 'locale' => $locale,
			'pinCipher' => $pin === '' ? null : $this->crypto->encrypt($pin)];
	}

	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private function folderPaths(Folder $root, array $paths): array {
		$result = [];
		foreach ($paths as $path) {
			if (!is_string($path)) throw new \InvalidArgumentException('Event folder paths must be strings');
			$path = trim($path, '/');
			if ($path === '' || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) throw new \InvalidArgumentException('Invalid event folder path');
			$node = $root->get($path);
			if (!$node instanceof Folder || !$root->isSubNode($node)) throw new \InvalidArgumentException('Event folder not found');
			$result[] = $path;
		}
		return array_values(array_unique($result));
	}

	/**
	 * @param list<string> $paths
	 * @return list<array{folderId: int, path: string}>
	 */
	private function folderRecords(Folder $root, array $paths): array {
		return array_map(function (string $path) use ($root): array {
			$folder = $root->get($path);
			if (!$folder instanceof Folder) throw new \InvalidArgumentException('Event folder not found');
			return ['folderId' => (int)$folder->getId(), 'path' => $path];
		}, $this->folderPaths($root, $paths));
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function present(array $row): array {
		return ['id' => (int)$row['id'], 'status' => (string)$row['status'], 'sharedRoots' => $this->decodeList((string)$row['shared_roots']),
			'expiresAt' => $row['expires_at'], 'releaseAt' => $row['release_at'] === null ? null : (int)$row['release_at'],
			'sendInvitations' => (bool)$row['send_invitations'], 'total' => (int)$row['total_count'], 'processed' => (int)$row['processed_count'],
			'failed' => (int)$row['failed_count'], 'pinExportAvailable' => $this->repository->handoffAvailable((int)$row['id'], $this->clock->getTime()),
			'createdAt' => (int)$row['created_at'], 'updatedAt' => (int)$row['updated_at']];
	}

	/** @return array<string, mixed> */
	private function owned(Gallery $gallery, int $waveId): array {
		$row = $this->repository->find($waveId);
		if ($row === false || (int)$row['gallery_id'] !== (int)$gallery->getId()) throw new \InvalidArgumentException('Event wave not found');
		return $row;
	}

	private function queue(int $waveId, ?int $releaseAt = null): void {
		$argument = ['waveId' => $waveId];
		if ($releaseAt === null) $this->jobs->add(ProcessEventWaveJob::class, $argument);
		else $this->jobs->scheduleAfter(ProcessEventWaveJob::class, $releaseAt, $argument);
	}

	private function failureCode(\Throwable $exception): string {
		return match (true) {
			$exception instanceof \OCP\Files\NotFoundException => 'folder_unavailable',
			$exception instanceof \OCP\HintException => 'password_policy_rejected',
			$exception instanceof \InvalidArgumentException => 'invalid_configuration',
			default => 'publication_failed',
		};
	}

	/** @return list<string> */
	private function resolvedRoots(Gallery $gallery, int $waveId, ?int $recipientId, string $role): array {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$prefix = rtrim($root->getPath(), '/') . '/';
		$result = [];
		foreach ($this->repository->roots($waveId, $recipientId, $role) as $reference) {
			$resolved = null;
			foreach ($root->getById((int)$reference['folder_id']) as $node) if ($node instanceof Folder && $root->isSubNode($node)) { $resolved = substr($node->getPath(), strlen($prefix)); break; }
			if ($resolved === null) throw new \InvalidArgumentException('Referenced event folder is unavailable');
			$result[] = $resolved;
		}
		return $result;
	}

	/** @param array<string, mixed> $recipient */
	private function resolvedPrivateRoot(Gallery $gallery, int $waveId, array $recipient): string {
		$roots = $this->resolvedRoots($gallery, $waveId, (int)$recipient['id'], 'private');
		return $roots[0] ?? throw new \InvalidArgumentException('Private event folder is unavailable');
	}

	/** @return list<string> */
	private function decodeList(string $json): array { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? array_values(array_filter($value, 'is_string')) : []; }
	/** @return array<string, mixed> */
	private function decodeMap(string $json): array { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
	private function assertEventGallery(Gallery $gallery): void { if ($gallery->getDeliveryMode() !== 'event') throw new \InvalidArgumentException('Event delivery is only available for event projects'); }
}
