<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PresetMapper;
use OCA\ProofingGallery\Domain\GalleryStatus;
use OCA\ProofingGallery\Domain\GalleryPurpose;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;

final class GalleryService {
	public function __construct(
		private GalleryMapper $mapper,
		private FolderService $folders,
		private MediaSummaryService $summaries,
		private CollectionService $collections,
		private GalleryAccessService $access,
		private PublicShareService $shares,
		private ITimeFactory $clock,
		private PolicyService $policies,
		private CapabilityPolicyService $capabilities,
		private UserPreferenceService $preferences,
		private PresetMapper $presets,
		private NotificationService $notifications,
	) {
	}

	/** @param array<string, mixed> $settings */
	public function create(
		string $ownerUid,
		string $title,
		?int $folderId,
		array $settings = [],
		string $sourceType = 'folder',
		string $purpose = 'custom',
	): Gallery {
		$this->capabilities->assertCanCreate($ownerUid);
		$galleryPurpose = GalleryPurpose::tryFrom($purpose) ?? throw new InvalidArgumentException('Unknown gallery purpose');
		if (!in_array($sourceType, ['folder', 'collection'], true)) {
			throw new InvalidArgumentException('Unknown gallery source type');
		}
		$anchor = null;
		if ($sourceType === 'folder') {
			if ($folderId === null) {
				throw new InvalidArgumentException('A source folder is required');
			}
			$this->folders->resolveFolder($ownerUid, $folderId);
		} else {
			$anchor = $this->collections->createAnchor($ownerUid);
			$folderId = $anchor->getId();
			$settings['allowGuestUploads'] = false;
		}
		$title = $this->validateTitle($title);
		$now = $this->clock->getTime();

		$gallery = new Gallery();
		$gallery->setOwnerUid($ownerUid);
		$gallery->setFolderId($folderId);
		$gallery->setSourceType($sourceType);
		$gallery->setTitle($title);
		$gallery->setSlug($this->uniqueSlug($ownerUid, $title));
		$gallery->setStatus(GalleryStatus::Draft->value);
		$gallery->setPurpose($galleryPurpose->value);
		$gallery->setWorkflowState('preparing');
		$preferences = $this->preferences->get($ownerUid);
		$personalDesign = [];
		if ($preferences['designPresetId'] !== null) {
			try {
				$preset = json_decode($this->presets->findOwned((int)$preferences['designPresetId'], $ownerUid)->getSettings(), true, flags: JSON_THROW_ON_ERROR);
				if (is_array($preset['presentation'] ?? null)) $personalDesign['presentation'] = $preset['presentation'];
			} catch (\Throwable) {
				// A removed personal preset cleanly falls back to instance defaults.
			}
		}
		$personalDefaults = [
			'publicLocale' => $preferences['publicLocale'],
			'lifecycle' => $preferences['lifecycle'],
		];
		$composed = array_replace_recursive(
			$galleryPurpose->settings(),
			$this->policies->galleryDefaults(),
			['presentation' => [
				'accentColor' => $this->policies->instanceSettings()['branding']['accentColor'],
				'instanceLogoAssetId' => $this->policies->instanceSettings()['branding']['logoAssetId'],
			]],
			$personalDefaults,
			$personalDesign,
			$settings,
		);
		$gallery->setSettings(json_encode(GallerySettings::merge(GallerySettings::defaults(), $composed), JSON_THROW_ON_ERROR));
		$gallery->setCreatedAt($now);
		$gallery->setUpdatedAt($now);

		try {
			$gallery = $this->mapper->insert($gallery);
			if ($sourceType === 'collection') {
				$this->collections->initialize($gallery);
			}
			$emailNotifications = $preferences['notifications']['email'];
			$nativeNotifications = $preferences['notifications']['nextcloud'];
			if (($emailNotifications['enabled'] && $emailNotifications['events'] !== [])
				|| ($nativeNotifications['enabled'] && $nativeNotifications['events'] !== [])) {
				try {
					$this->notifications->save(
						$ownerUid,
						(int)$gallery->getId(),
						$ownerUid,
						$emailNotifications['events'],
						$emailNotifications['frequency'],
						$preferences['publicLocale'],
						$emailNotifications['enabled'],
						$nativeNotifications['enabled'],
						$nativeNotifications['events'],
					);
				} catch (\Throwable) {
					// Missing email addresses must not make gallery creation fail.
				}
			}
			return $gallery;
		} catch (\Throwable $exception) {
			if ($gallery->getId() !== null) {
				try {
					$this->mapper->delete($gallery);
				} catch (\Throwable) {
				}
			}
			if ($anchor !== null) {
				try {
					$this->collections->deleteAnchor($anchor);
				} catch (\Throwable) {
				}
			}
			throw $exception;
		}
	}

	/** @param array<string, mixed> $settings */
	public function createProject(
		string $ownerUid,
		string $title,
		string $purpose,
		string $sourceMode,
		?int $folderId,
		?int $parentFolderId,
		?string $folderName,
		array $settings = [],
	): Gallery {
		$preferences = $this->preferences->get($ownerUid);
		if ($purpose === '') {
			$purpose = (string)($preferences['defaultPurpose'] ?? $this->policies->instanceSettings()['workflow']['defaultPurpose']);
		}
		if ($sourceMode === 'new' && $parentFolderId === null && is_array($preferences['parentFolder'])) {
			$parentFolderId = (int)$preferences['parentFolder']['id'];
		}
		$createdFolder = null;
		try {
			if ($sourceMode === 'new') {
				if ($parentFolderId === null || $folderName === null) {
					throw new InvalidArgumentException('A parent folder and folder name are required');
				}
				$createdFolder = $this->folders->createProjectFolder($ownerUid, $parentFolderId, $folderName);
				$folderId = $createdFolder->getId();
			} elseif ($sourceMode !== 'existing' && $sourceMode !== 'collection') {
				throw new InvalidArgumentException('Unknown project source mode');
			}
			return $this->create(
				$ownerUid,
				$title,
				$sourceMode === 'collection' ? null : $folderId,
				$settings,
				$sourceMode === 'collection' ? 'collection' : 'folder',
				$purpose,
			);
		} catch (\Throwable $exception) {
			if ($createdFolder !== null) {
				try {
					if ($createdFolder->getDirectoryListing() === []) $createdFolder->delete();
				} catch (\Throwable) {
					// Preserve the project creation error; cleanup is best effort.
				}
			}
			throw $exception;
		}
	}

	/** @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function list(
		string $ownerUid,
		int $limit,
		int $offset,
		bool $archived,
		string $search,
		?string $sourceType = null,
		bool $ownedOnly = false,
	): array {
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$search = mb_substr(trim($search), 0, 120);
		if ($sourceType !== null && !in_array($sourceType, ['folder', 'collection'], true)) {
			throw new InvalidArgumentException('Unknown gallery source type');
		}

		$all = $this->access->list($ownerUid, $archived, $search);
		$all = array_values(array_filter(
			$all,
			static fn (Gallery $gallery): bool => (!$ownedOnly || $gallery->getOwnerUid() === $ownerUid)
				&& ($sourceType === null || $gallery->getSourceType() === $sourceType),
		));
		return [
			'items' => array_map(
				fn (Gallery $gallery): array => $this->present($ownerUid, $gallery),
				array_slice($all, $offset, $limit),
			),
			'total' => count($all),
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	public function get(string $ownerUid, int $id): Gallery {
		return $this->access->owner($ownerUid, $id);
	}

	public function view(string $userId, int $id): Gallery {
		return $this->access->view($userId, $id);
	}

	/** @return array<string, mixed> */
	public function present(string $userId, Gallery $gallery): array {
		$permissions = $this->access->permissions($userId, $gallery);
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$effectiveCapabilities = $this->capabilities->effective($settings, $userId);
		if ($gallery->getSourceType() === 'collection') {
			return [
				...$gallery->jsonSerialize(),
				'source' => $this->collections->sourceStatus($gallery),
				'mediaSummary' => $this->collections->summary($gallery),
				'permissions' => $permissions,
				'effectiveCapabilities' => $effectiveCapabilities,
			];
		}
		try {
			$folder = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
			$source = $this->folders->describeSource(
				$gallery->getOwnerUid(),
				$gallery->getFolderId(),
				$folder,
				$permissions['role'] === 'owner',
			);
			$source['type'] = 'folder';
			$mediaSummary = $this->summaries->forFolder(
				$gallery->getId(),
				$gallery->getFolderId(),
				$folder,
			);
		} catch (\OCA\ProofingGallery\Exception\FolderAccessException) {
			$source = [
					'type' => 'folder',
					'folderId' => $gallery->getFolderId(),
					'displayPath' => null,
					'state' => 'missing',
			];
			$mediaSummary = [
					'total' => 0,
					'coverFileId' => null,
					'coverMimeType' => null,
			];
		}

		return [
			...$gallery->jsonSerialize(),
			'source' => $source,
			'mediaSummary' => $mediaSummary,
			'permissions' => $permissions,
			'effectiveCapabilities' => $effectiveCapabilities,
		];
	}

	public function rebindSource(string $ownerUid, int $id, int $folderId): Gallery {
		$gallery = $this->access->owner($ownerUid, $id);
		if ($gallery->getSourceType() !== 'folder') {
			throw new InvalidArgumentException('Collections do not have a replaceable source folder');
		}
		$this->folders->resolveFolder($ownerUid, $folderId);
		return $this->shares->rebindSource($gallery, $folderId);
	}

	/** @param array<string, mixed>|null $settings */
	public function update(string $ownerUid, int $id, ?string $title, ?array $settings, ?int $expectedRevision = null): Gallery {
		$gallery = $this->access->edit($ownerUid, $id);
		$revision = $expectedRevision ?? $gallery->getRevision();
		if ($title !== null) {
			$gallery->setTitle($this->validateTitle($title));
		}
		if ($settings !== null) {
			if ($gallery->getSourceType() === 'collection' && ($settings['allowGuestUploads'] ?? false) === true) {
				throw new InvalidArgumentException('Guest uploads are unavailable for collections');
			}
			$current = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$gallery->setSettings(json_encode(GallerySettings::merge($current, $settings), JSON_THROW_ON_ERROR));
		}
		$gallery->setUpdatedAt($this->clock->getTime());

		return $this->mapper->updateDocument($gallery, $revision);
	}

	public function archive(string $ownerUid, int $id): Gallery {
		$gallery = $this->get($ownerUid, $id);
		$now = $this->clock->getTime();
		$gallery->setStatus(GalleryStatus::Archived->value);
		$gallery->setArchivedAt($now);
		$gallery->setUpdatedAt($now);
		$gallery->setRevision($gallery->getRevision() + 1);

		return $this->mapper->update($gallery);
	}

	public function restore(string $ownerUid, int $id): Gallery {
		$gallery = $this->get($ownerUid, $id);
		if ($gallery->getStatus() !== GalleryStatus::Archived->value) {
			throw new InvalidArgumentException('Only archived galleries can be restored');
		}
		$gallery->setStatus($gallery->getShareToken() === null
			? GalleryStatus::Draft->value
			: GalleryStatus::Published->value);
		$gallery->setArchivedAt(null);
		$gallery->setUpdatedAt($this->clock->getTime());
		$gallery->setRevision($gallery->getRevision() + 1);

		return $this->mapper->update($gallery);
	}

	public function complete(string $ownerUid, int $id): Gallery {
		$gallery = $this->access->edit($ownerUid, $id);
		$now = $this->clock->getTime();
		$gallery->setWorkflowState('completed');
		$gallery->setCompletedAt($now);
		$gallery->setUpdatedAt($now);
		$gallery->setRevision($gallery->getRevision() + 1);
		return $this->mapper->update($gallery);
	}

	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new InvalidArgumentException('Title must contain 1 to 255 characters');
		}
		return $title;
	}

	private function uniqueSlug(string $ownerUid, string $title): string {
		$base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title)) ?? '', '-');
		$base = mb_substr($base !== '' ? $base : 'gallery', 0, 80);
		$slug = $base;
		$suffix = 2;
		while ($this->mapper->slugExists($ownerUid, $slug)) {
			$slug = $base . '-' . $suffix++;
		}
		return $slug;
	}
}
