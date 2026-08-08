<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PresetMapper;
use OCA\ProofingGallery\Db\MediaSummaryRepository;
use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Domain\GalleryStatus;
use OCA\ProofingGallery\Domain\GalleryPurpose;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;

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
		private IJobList $jobs,
		private LifecycleScheduleService $lifecycleSchedule,
		private GalleryListProjectionService $listProjection,
		private GalleryCursorCodec $galleryCursors,
		private MediaSummaryRepository $summaryRows,
		private CollectionRepository $collectionRows,
		private RetentionHandoffService $retention,
	) {
	}

	/**
	 * @param array<string, mixed> $settings
	 */
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
		$gallerySettings = GallerySettings::merge(GallerySettings::defaults(), $composed);
		$this->assertPresentationAssets($gallery, $gallerySettings);
		$gallery->setSettings(json_encode($gallerySettings, JSON_THROW_ON_ERROR));
		$gallery->setCreatedAt($now);
		$gallery->setUpdatedAt($now);
		$this->lifecycleSchedule->project($gallery, $now);
		$this->listProjection->project($gallery);

		try {
			$gallery = $this->mapper->insert($gallery);
			if ($sourceType === 'folder') $this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => $gallery->getId()]);
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

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $designPreset
	 */
	public function createProject(
		string $ownerUid,
		string $title,
		string $purpose,
		string $sourceMode,
		?int $folderId,
		?int $parentFolderId,
		?string $folderName,
		array $settings = [],
		array $designPreset = ['mode' => 'inherit'],
	): Gallery {
		$preferences = $this->preferences->get($ownerUid);
		$settings = array_replace_recursive($this->selectedDesign($ownerUid, $preferences, $designPreset), $settings);
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

	/**
	 * @param array<string, mixed> $preferences
	 * @param array<string, mixed> $selection
	 * @return array<string, mixed>
	 */
	private function selectedDesign(string $ownerUid, array $preferences, array $selection): array {
		$mode = (string)($selection['mode'] ?? 'inherit');
		if (!in_array($mode, ['inherit', 'instance', 'preset'], true)) {
			throw new InvalidArgumentException('Unknown design preset mode');
		}
		if ($mode === 'instance') return [];
		$presetId = $mode === 'preset' ? (int)($selection['id'] ?? 0) : (int)($preferences['designPresetId'] ?? 0);
		if ($mode === 'preset' && $presetId < 1) throw new InvalidArgumentException('A design preset is required');
		if ($presetId < 1) return [];
		try {
			$preset = json_decode($this->presets->findOwned($presetId, $ownerUid)->getSettings(), true, flags: JSON_THROW_ON_ERROR);
			return is_array($preset['presentation'] ?? null) ? ['presentation' => $preset['presentation']] : [];
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			if ($mode === 'preset') throw new InvalidArgumentException('The selected design preset no longer exists');
			return [];
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

	/** @return array{items: list<array<string, mixed>>, total: int, nextCursor: ?string} */
	public function listV2(
		string $userUid,
		int $limit,
		?string $cursor,
		bool $archived,
		string $search,
		?string $sourceType,
		?string $status,
		?string $mode,
		?string $purpose,
		bool $ownedOnly,
		string $sort,
	): array {
		$limit = max(1, min(100, $limit));
		$search = mb_substr(trim($search), 0, 120);
		if ($sourceType !== null && !in_array($sourceType, ['folder', 'collection'], true)) throw new InvalidArgumentException('Unknown gallery source type');
		if ($status !== null && !in_array($status, ['draft', 'published', 'archived'], true)) throw new InvalidArgumentException('Unknown gallery status');
		if ($mode !== null && !in_array($mode, ['presentation', 'collaboration'], true)) throw new InvalidArgumentException('Unknown gallery mode');
		if ($purpose !== null && GalleryPurpose::tryFrom($purpose) === null) throw new InvalidArgumentException('Unknown gallery purpose');
		if (!in_array($sort, ['updated', 'created', 'title'], true)) throw new InvalidArgumentException('Unknown gallery sort');
		if ($archived && $status !== null && $status !== 'archived') throw new InvalidArgumentException('Status does not match archive view');
		$scope = compact('archived', 'search', 'sourceType', 'status', 'mode', 'purpose', 'ownedOnly');
		$decoded = $this->galleryCursors->decode($cursor, $sort, $scope);
		$page = $this->access->page($userUid, $archived, $search, $sourceType, $status, $mode, $purpose, $ownedOnly, $sort, $decoded, $limit + 1);
		$hasMore = count($page['items']) > $limit;
		$galleries = array_slice($page['items'], 0, $limit);
		$ids = array_map(static fn (Gallery $gallery): int => (int)$gallery->getId(), $galleries);
		$summaries = $this->summaryRows->findMany($ids);
		$collectionIds = [];
		foreach ($galleries as $gallery) if ($gallery->getSourceType() === 'collection') $collectionIds[] = (int)$gallery->getId();
		$collectionCounts = $this->collectionRows->counts($collectionIds);
		$items = array_map(function (Gallery $gallery) use ($userUid, $page, $summaries, $collectionCounts): array {
			$id = (int)$gallery->getId();
			$role = $gallery->getOwnerUid() === $userUid ? 'owner' : ($page['roles'][$id] ?? 'viewer');
			$summary = $summaries[$id] ?? null;
			return [
				'id' => $id,
				'title' => $gallery->getTitle(),
				'status' => $gallery->getStatus(),
				'mode' => $gallery->getMode(),
				'sourceType' => $gallery->getSourceType(),
				'purpose' => $gallery->getPurpose(),
				'workflowState' => $gallery->getWorkflowState(),
				'createdAt' => $gallery->getCreatedAt(),
				'updatedAt' => $gallery->getUpdatedAt(),
				'heroFileId' => GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR))->presentation->heroFileId,
				'lifecycleNextAt' => $gallery->getLifecycleNextAt(),
				'mediaSummary' => [
					'total' => $gallery->getSourceType() === 'collection' ? ($collectionCounts[$id] ?? 0) : (int)($summary['media_total'] ?? 0),
					'coverFileId' => isset($summary['cover_file_id']) ? (int)$summary['cover_file_id'] : null,
					'coverMimeType' => $summary['cover_mime_type'] ?? null,
				],
				'permissions' => [
					'role' => $role,
					'canEdit' => $role === 'owner' || $role === 'editor',
					'canManageAccess' => $role === 'owner',
					'canArchive' => $role === 'owner',
				],
			];
		}, $galleries);
		$last = $galleries === [] ? null : $galleries[array_key_last($galleries)];
		$nextCursor = $hasMore && $last !== null ? $this->galleryCursors->encode(
			$sort,
			$sort === 'title' ? $last->getTitleSort() : ($sort === 'created' ? $last->getCreatedAt() : $last->getUpdatedAt()),
			(int)$last->getId(),
			$scope,
		) : null;
		return ['items' => $items, 'total' => $page['total'], 'nextCursor' => $nextCursor];
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
				'retention' => $this->retention->status($gallery),
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
			'retention' => $this->retention->status($gallery),
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
			$merged = GallerySettings::merge($current, $settings);
			$this->assertPresentationAssets($gallery, $merged);
			$gallery->setSettings(json_encode($merged, JSON_THROW_ON_ERROR));
		}
		$gallery->setUpdatedAt($this->clock->getTime());
		$this->lifecycleSchedule->project($gallery, $this->clock->getTime());
		$this->listProjection->project($gallery);

		$updated = $this->mapper->updateDocument($gallery, $revision);
		$this->shares->synchronizePrimaryNavigation($updated);
		return $updated;
	}

	public function archive(string $ownerUid, int $id): Gallery {
		$gallery = $this->get($ownerUid, $id);
		$archived = $this->shares->archive($gallery);
		$this->retention->assignOnArchive($archived, $ownerUid);
		return $archived;
	}

	public function restore(string $ownerUid, int $id): Gallery {
		$gallery = $this->get($ownerUid, $id);
		if ($gallery->getStatus() !== GalleryStatus::Archived->value) {
			throw new InvalidArgumentException('Only archived galleries can be restored');
		}
		$this->retention->remove($gallery, $ownerUid);
		return $this->shares->restore($gallery);
	}

	public function complete(string $ownerUid, int $id): Gallery {
		$gallery = $this->access->edit($ownerUid, $id);
		$now = $this->clock->getTime();
		$gallery->setWorkflowState('completed');
		$gallery->setCompletedAt($now);
		$gallery->setUpdatedAt($now);
		$gallery->setRevision($gallery->getRevision() + 1);
		$this->lifecycleSchedule->project($gallery, $now);
		return $this->mapper->update($gallery);
	}

	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new InvalidArgumentException('Title must contain 1 to 255 characters');
		}
		return $title;
	}

	private function assertPresentationAssets(Gallery $gallery, GallerySettings $settings): void {
		foreach ([$settings->presentation->heroFileId, $settings->presentation->logoFileId] as $fileId) {
			if ($fileId === null) continue;
			try {
				$file = $gallery->getSourceType() === 'collection'
					? $this->collections->resolveMedia($gallery, $fileId)
					: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
			} catch (\OCA\ProofingGallery\Exception\FolderAccessException $exception) {
				throw new InvalidArgumentException('Gallery artwork must be an image inside the gallery source', previous: $exception);
			}
			if (!str_starts_with($file->getMimeType(), 'image/')) {
				throw new InvalidArgumentException('Gallery artwork must be an image inside the gallery source');
			}
		}
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
