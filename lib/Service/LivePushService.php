<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\IndexMediaMetadataJob;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\LivePushRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ISecureRandom;

final class LivePushService {
	public function __construct(
		private LivePushRepository $repository,
		private GalleryMapper $galleries,
		private FolderService $folders,
		private MediaSummaryService $summaries,
		private ActivityService $activity,
		private PolicyService $policies,
		private ISecureRandom $random,
		private ITimeFactory $clock,
		private IJobList $jobs,
	) {
	}

	/** @return array<string, mixed> */
	public function overview(Gallery $gallery): array {
		return [
			'connection' => $this->policies->livePushSettings(),
			'items' => array_map($this->present(...), $this->repository->gallery((int)$gallery->getId())),
		];
	}

	/** @return array<string, mixed> */
	public function create(Gallery $gallery, string $createdBy, string $label, string $path): array {
		$this->assertAvailable($gallery);
		$active = array_filter($this->repository->gallery((int)$gallery->getId()), static fn (array $row): bool => $row['revoked_at'] === null);
		if (count($active) >= $this->policies->get('maxLivePushCredentials')) throw new \InvalidArgumentException('Live Push credential limit reached');
		$label = trim($label);
		$path = trim($path, '/ ');
		if ($label === '' || mb_strlen($label) > 80) throw new \InvalidArgumentException('Credential name must contain 1 to 80 characters');
		if (mb_strlen($path) > 500 || str_contains('/' . $path . '/', '/../')) throw new \InvalidArgumentException('Invalid destination path');
		$username = 'pg-g' . $gallery->getId() . '-' . mb_strtolower($this->random->generate(12, ISecureRandom::CHAR_ALPHANUMERIC));
		$secret = $this->secret();
		$id = $this->repository->create((int)$gallery->getId(), $username, self::hash($secret), $label, $path, $createdBy, $this->clock->getTime());
		return [...$this->present($this->repository->activeByUsername($username) ?? ['id' => $id, 'username' => $username]), 'password' => $secret];
	}

	/** @return array<string, mixed> */
	public function rotate(Gallery $gallery, int $id): array {
		$this->assertAvailable($gallery);
		$secret = $this->secret();
		if (!$this->repository->rotate((int)$gallery->getId(), $id, self::hash($secret))) throw new \InvalidArgumentException('Live Push credential not found');
		$row = current(array_filter($this->repository->gallery((int)$gallery->getId()), static fn (array $item): bool => (int)$item['id'] === $id));
		if (!is_array($row)) throw new \RuntimeException('Rotated credential could not be read');
		return [...$this->present($row), 'password' => $secret];
	}

	public function revoke(Gallery $gallery, int $id): void {
		if (!$this->repository->revoke((int)$gallery->getId(), $id, $this->clock->getTime())) throw new \InvalidArgumentException('Active Live Push credential not found');
	}

	/** @return array{credential: array<string, mixed>, gallery: Gallery} */
	public function authenticate(string $authorization): array {
		if (!str_starts_with($authorization, 'Basic ')) throw new \UnexpectedValueException('Invalid Live Push credentials');
		$decoded = base64_decode(substr($authorization, 6), true);
		if (!is_string($decoded) || !str_contains($decoded, ':')) throw new \UnexpectedValueException('Invalid Live Push credentials');
		[$username, $secret] = explode(':', $decoded, 2);
		$row = $this->repository->activeByUsername($username);
		if ($row === null || !hash_equals((string)$row['secret_hash'], self::hash($secret))) throw new \UnexpectedValueException('Invalid Live Push credentials');
		$gallery = $this->galleries->find((int)$row['gallery_id']);
		$this->assertAvailable($gallery);
		return ['credential' => $row, 'gallery' => $gallery];
	}

	/** @param array<string, mixed> $credential
	 * @return array<string, mixed>
	 */
	public function upload(array $credential, Gallery $gallery, string $filename, string $temporaryPath, int $size): array {
		if ($size < 1 || $size > $this->policies->get('maxUploadBytes')) throw new \InvalidArgumentException('Upload size is outside the allowed range');
		$item = $this->folders->uploadMedia($gallery->getOwnerUid(), $gallery->getFolderId(), (string)$credential['target_path'], $filename, $temporaryPath, 'rename');
		if ($item === null) throw new \RuntimeException('Live Push upload was skipped');
		$now = $this->clock->getTime();
		$this->repository->recordUpload((int)$credential['id'], $size, $now);
		$this->summaries->invalidate((int)$gallery->getId());
		$this->jobs->add(IndexMediaMetadataJob::class, ['galleryId' => (int)$gallery->getId(), 'fileId' => $item->id]);
		$this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => (int)$gallery->getId()]);
		$this->activity->record($gallery, null, 'live_push.uploaded', ['filename' => $item->name, 'size' => $size, 'credential' => $credential['label']]);
		return ['item' => $item, 'receivedAt' => $now];
	}

	private function assertAvailable(Gallery $gallery): void {
		if (!$this->policies->livePushSettings()['enabled']) throw new \InvalidArgumentException('Live Push is disabled by the administrator');
		if ($gallery->getSourceType() !== 'folder' || $gallery->getStatus() === 'archived') throw new \InvalidArgumentException('Live Push requires an active folder gallery');
	}

	/** @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function present(array $row): array {
		return [
			'id' => (int)$row['id'], 'username' => (string)$row['username'], 'label' => (string)($row['label'] ?? ''),
			'path' => (string)($row['target_path'] ?? ''), 'createdAt' => (int)($row['created_at'] ?? 0),
			'lastUsedAt' => ($row['last_used_at'] ?? null) === null ? null : (int)$row['last_used_at'],
			'revokedAt' => ($row['revoked_at'] ?? null) === null ? null : (int)$row['revoked_at'],
			'uploadCount' => (int)($row['upload_count'] ?? 0), 'bytesReceived' => (int)($row['bytes_received'] ?? 0),
		];
	}

	private function secret(): string {
		return $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
	}

	private static function hash(string $secret): string {
		return hash('sha256', $secret);
	}
}
