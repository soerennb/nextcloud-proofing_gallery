<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\KioskRepository;
use OCA\ProofingGallery\Db\PresetMapper;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Exception\KioskConflictException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use OCP\IConfig;

/** One resumable workflow shared by the owner dialog and external clients. */
final class KioskGalleryService {
	public function __construct(
		private KioskRepository $kiosks,
		private GalleryService $galleries,
		private GalleryMapper $galleryRows,
		private PublicShareService $shares,
		private PublicLinkMapper $publicLinks,
		private FolderService $folders,
		private UserPreferenceService $preferences,
		private PresetMapper $presets,
		private CapabilityPolicyService $capabilities,
		private CoreSharingPolicyService $coreSharing,
		private PolicyService $policies,
		private UploadLockService $locks,
		private KioskLinkService $links,
		private ITimeFactory $clock,
		private IURLGenerator $urls,
		private IConfig $config,
	) {
	}

	/** @return array<string, mixed> */
	public function setup(string $ownerUid): array {
		$preferences = $this->preferences->get($ownerUid);
		return [
			'schemaVersion' => 1, 'defaults' => [
				'parentFolder' => $preferences['parentFolder'], 'designPresetId' => $preferences['designPresetId'],
			],
			'capabilities' => $this->capabilities->effective(null, $ownerUid),
			'sharingPolicy' => $this->coreSharing->status(),
			'upload' => ['mimeTypes' => ['image/jpeg'], 'maxBytes' => $this->policies->get('maxUploadBytes'), 'perMinute' => 60],
		];
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function create(string $ownerUid, string $eventId, string $title, ?int $parentFolderId, ?int $designPresetId, string $password, string $expiresAt): array {
		$eventId = trim($eventId);
		$title = trim($title);
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $eventId) !== 1) throw new \InvalidArgumentException('eventId must contain 1 to 128 URL-safe characters');
		if ($title === '' || mb_strlen($title) > 255) throw new \InvalidArgumentException('Title must contain 1 to 255 characters');
		$this->capabilities->assertCanCreate($ownerUid);
		$this->capabilities->assertCanPublish($ownerUid);
		$requestHash = hash_hmac('sha256', json_encode(compact('title', 'parentFolderId', 'designPresetId', 'password', 'expiresAt'), JSON_THROW_ON_ERROR), $this->config->getSystemValueString('secret'));
		$key = hash('sha256', $ownerUid . "\0" . $eventId);
		return $this->locks->wait('proofing-gallery/kiosk-event/' . $key, 'Fotobox event setup', function () use ($ownerUid, $eventId, $title, $parentFolderId, $designPresetId, $password, $expiresAt, $requestHash): array {
			$row = $this->kiosks->event($ownerUid, $eventId);
			if ($row !== null && !hash_equals((string)$row['request_hash'], $requestHash)) throw new KioskConflictException('eventId was already used with different setup parameters');
			if ($row === null) {
				$config = $this->configuration($ownerUid, $title, $parentFolderId, $designPresetId, $password, $expiresAt);
				$this->kiosks->reserveEvent($ownerUid, $eventId, $requestHash, $config, $this->clock->getTime());
				$row = $this->kiosks->event($ownerUid, $eventId);
			}
			if ($row === null) throw new \RuntimeException('Kiosk event could not be reserved');
			$replayed = $row['state'] === 'ready';
			if (!$replayed) $this->provision($ownerUid, $row, $password);
			$event = $this->kiosks->event($ownerUid, $eventId);
			if ($event === null) throw new \RuntimeException('Kiosk event could not be loaded');
			$gallery = $this->links->gallery($ownerUid, (int)$event['gallery_id']);
			return ['data' => $this->connection($ownerUid, $eventId, $gallery), 'replayed' => $replayed];
		});
	}

	/** @return array<string, mixed> */
	private function configuration(string $ownerUid, string $title, ?int $parentFolderId, ?int $designPresetId, string $password, string $expiresAt): array {
		$preferences = $this->preferences->get($ownerUid);
		$parentFolderId ??= (int)($preferences['parentFolder']['id'] ?? 0);
		if ($parentFolderId < 1) throw new \InvalidArgumentException('Choose a default parent folder or supply parentFolderId');
		$parent = $this->folders->resolveFolder($ownerUid, $parentFolderId);
		if (!$parent->isUpdateable()) throw new \InvalidArgumentException('The parent folder is not writable');
		$designPresetId ??= (int)($preferences['designPresetId'] ?? 0);
		if ($designPresetId < 0) throw new \InvalidArgumentException('Invalid designPresetId');
		if ($designPresetId > 0) $this->presets->findOwned($designPresetId, $ownerUid);
		$sharing = $this->coreSharing->status();
		if (!$sharing['publicLinksAllowed']) throw new \InvalidArgumentException('Public links are disabled by Nextcloud');
		if ($sharing['passwordEnforced'] && $password === '') throw new \InvalidArgumentException('A gallery password is required by the administrator');
		if ($expiresAt === '' && ($sharing['expirationEnabled'] || $sharing['expirationEnforced']) && $sharing['expirationDays'] !== null) {
			$expiresAt = gmdate('Y-m-d', $this->clock->getTime() + (int)$sharing['expirationDays'] * 86400);
		}
		if ($expiresAt !== '') {
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiresAt);
			if ($sharing['expirationEnforced'] && $sharing['expirationDays'] !== null && $expiresAt > gmdate('Y-m-d', $this->clock->getTime() + (int)$sharing['expirationDays'] * 86400)) throw new \InvalidArgumentException('expiresAt exceeds the Nextcloud expiration policy');
			if ($date === false || $date->format('Y-m-d') !== $expiresAt || $date->getTimestamp() <= $this->clock->getTime()) throw new \InvalidArgumentException('expiresAt must be a future date in YYYY-MM-DD format');
		}
		return compact('title', 'parentFolderId', 'designPresetId', 'expiresAt');
	}

	/** @param array<string, mixed> $event */
	private function provision(string $ownerUid, array $event, string $password): void {
		$config = json_decode((string)$event['config_json'], true, flags: JSON_THROW_ON_ERROR);
		$folder = $this->eventFolder($ownerUid, $event, $config);
		if ($event['gallery_id'] === null) {
			$gallery = $this->kiosks->transaction(function () use ($ownerUid, $event, $config, $folder): Gallery {
				$design = (int)$config['designPresetId'] > 0 ? ['mode' => 'preset', 'id' => (int)$config['designPresetId']] : ['mode' => 'instance'];
				$gallery = $this->galleries->createProject($ownerUid, (string)$config['title'], 'delivery', 'existing', (int)$folder->getId(), null, null, [
					'mode' => 'presentation', 'navigation' => ['recursive' => false, 'sortBy' => 'modified', 'sortDirection' => 'desc', 'groupBy' => 'none'],
					'delivery' => ['downloadScope' => $this->capabilities->feature('downloads') ? 'individual' : 'none', 'guestUploads' => false],
					'review' => ['likes' => false, 'colors' => false, 'comments' => false, 'annotations' => false, 'selections' => false, 'ratings' => false, 'pick' => false],
				], $design, 'standard');
				$this->kiosks->bindGallery((int)$event['id'], (int)$gallery->getId());
				return $gallery;
			});
		} else {
			$gallery = $this->galleryRows->findOwned((int)$event['gallery_id'], $ownerUid);
		}
		$this->kiosks->transaction(function () use ($event, $config, $gallery, $password): void {
			if ($gallery->getStatus() !== 'draft') throw new KioskConflictException('Incomplete kiosk setup was changed; finish setup in the gallery');
			$updated = $this->shares->publish($gallery, $password, (string)$config['expiresAt'], $this->capabilities->feature('downloads') ? 'individual' : 'none');
			$link = $this->publicLinks->findPrimary((int)$updated->getId());
			$this->kiosks->ready((int)$event['id'], (int)$link->getId());
		});
	}

	/** @param array<string, mixed> $event
	 * @param array<string, mixed> $config
	 */
	private function eventFolder(string $ownerUid, array $event, array $config): Folder {
		if ($event['folder_id'] !== null) return $this->folders->resolveFolder($ownerUid, (int)$event['folder_id']);
		$parent = $this->folders->resolveFolder($ownerUid, (int)$config['parentFolderId']);
		$name = 'Fotobox-' . substr(hash('sha256', $ownerUid . "\0" . $event['event_key']), 0, 24);
		$folder = $parent->nodeExists($name) ? $parent->get($name) : $this->folders->createProjectFolder($ownerUid, (int)$parent->getId(), $name);
		if (!$folder instanceof Folder || $folder->getDirectoryListing() !== []) throw new KioskConflictException('The reserved kiosk folder is not an empty directory');
		$this->kiosks->bindFolder((int)$event['id'], (int)$folder->getId());
		return $folder;
	}

	/** @return array<string, mixed> */
	private function connection(string $ownerUid, string $eventId, Gallery $gallery): array {
		return [
			'schemaVersion' => 1, 'eventId' => $eventId, 'gallery' => $this->galleries->present($ownerUid, $gallery),
			'galleryUrl' => $this->links->galleryUrl($gallery),
			'upload' => [
				'urlTemplate' => $this->urls->linkToOCSRouteAbsolute('proofing_gallery.kiosk.upload', ['galleryId' => $gallery->getId(), 'photoId' => 'PHOTO_ID']) . '?format=json',
				'photoIdPlaceholder' => 'PHOTO_ID', 'method' => 'PUT', 'authentication' => 'nextcloud-app-password',
				'mimeTypes' => ['image/jpeg'], 'maxBytes' => $this->policies->get('maxUploadBytes'), 'perMinute' => 60,
			],
		];
	}
}
