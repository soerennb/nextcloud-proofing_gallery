<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PresetMapper;
use OCA\ProofingGallery\Domain\GalleryStatus;
use OCA\ProofingGallery\Domain\GalleryPurpose;
use OCA\ProofingGallery\Domain\ProjectCreationOptions;
use OCA\ProofingGallery\Domain\ProjectSettingsComposer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\BackgroundJob\IJobList;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;

final class GalleryCreationService {
	use TTransactional;
	public function __construct(
		private GalleryMapper $mapper,
		private FolderService $folders,
		private CollectionService $collections,
		private ITimeFactory $clock,
		private PolicyService $policies,
		private CapabilityPolicyService $capabilities,
		private UserPreferenceService $preferences,
		private PresetMapper $presets,
		private NotificationService $notifications,
		private IJobList $jobs,
		private LifecycleScheduleService $lifecycleSchedule,
		private GalleryListProjectionService $listProjection,
		private GalleryInputValidator $input,
		private IDBConnection $db,
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
		string $deliveryMode = 'standard',
	): Gallery {
		$this->capabilities->assertCanCreate($ownerUid);
		$title = $this->input->validateTitle($title);
		$galleryPurpose = GalleryPurpose::tryFrom($purpose) ?? throw new InvalidArgumentException('Unknown gallery purpose');
		if (!in_array($sourceType, ['folder', 'collection'], true)) {
			throw new InvalidArgumentException('Unknown gallery source type');
		}
		if (!in_array($deliveryMode, ['standard', 'event'], true) || ($deliveryMode === 'event' && $sourceType !== 'folder')) {
			throw new InvalidArgumentException('Unknown gallery delivery mode');
		}
		$anchor = null;
		if ($sourceType === 'folder') {
			if ($folderId === null) {
				throw new InvalidArgumentException('A source folder is required');
			}
			$this->folders->resolveFolder($ownerUid, $folderId);
		} else {
			// An empty collection has no media references until membership is saved.
			$folderId = 0;
			$settings['delivery']['guestUploads'] = false;
			unset($settings['allowGuestUploads']);
		}
		$now = $this->clock->getTime();

		$gallery = new Gallery();
		$gallery->setOwnerUid($ownerUid);
		$gallery->setFolderId($folderId);
		$gallery->setSourceType($sourceType);
		$gallery->setTitle($title);
		$gallery->setSlug($this->uniqueSlug($ownerUid, $title));
		$gallery->setStatus(GalleryStatus::Draft->value);
		$gallery->setPurpose($galleryPurpose->value);
		$gallery->setDeliveryMode($deliveryMode);
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
		if (($settings['presentation']['logoMode'] ?? null) === 'upload'
			&& !array_key_exists('logoBackground', $settings['presentation'])) {
			$settings['presentation']['logoBackground'] = 'light';
		}
		$gallerySettings = ProjectSettingsComposer::compose(
			$galleryPurpose,
			$this->policies->galleryDefaults(),
			['presentation' => [
				'accentColor' => $this->policies->instanceSettings()['branding']['accentColor'],
				'instanceLogoAssetId' => $this->policies->instanceSettings()['branding']['logoAssetId'],
				'instanceStudioName' => $this->policies->instanceSettings()['branding']['studioName'],
			]],
			$personalDefaults,
			$personalDesign,
			$settings,
		);
		\OCA\ProofingGallery\Domain\MediaSort::assertValid($gallerySettings->navigation->sortBy, $gallerySettings->navigation->sortDirection, $sourceType === 'collection');
		$this->input->assertPresentationAssets($gallery, $gallerySettings);
		$gallery->setSettings(json_encode($gallerySettings, JSON_THROW_ON_ERROR));
		$gallery->setCreatedAt($now);
		$gallery->setUpdatedAt($now);
		$this->lifecycleSchedule->project($gallery, $now);
		$this->listProjection->project($gallery);

		try {
			if ($sourceType === 'collection') {
				$anchor = $this->collections->createAnchor($ownerUid);
				$gallery->setFolderId($anchor->getId());
			}
			$gallery = $this->atomic(function () use ($gallery, $sourceType): Gallery {
				$created = $this->mapper->insert($gallery);
				if ($sourceType === 'folder') $this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => $created->getId()]);
				else $this->collections->initialize($created);
				return $created;
			}, $this->db);
		} catch (\Throwable $exception) {
			if ($anchor !== null) {
				try {
					$this->collections->deleteAnchor($anchor);
				} catch (\Throwable) {
				}
			}
			throw $exception;
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
		string $deliveryMode = 'standard',
	): Gallery {
		$title = $this->input->validateTitle($title);
		$preferences = $this->preferences->get($ownerUid);
		$settings = array_replace_recursive($this->selectedDesign($ownerUid, $preferences, $designPreset), $settings);
		if ($purpose === '') {
			$purpose = (string)($preferences['defaultPurpose'] ?? $this->policies->instanceSettings()['workflow']['defaultPurpose']);
		}
		ProjectCreationOptions::assertValid($purpose, $deliveryMode, $sourceMode);
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
				$deliveryMode,
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
