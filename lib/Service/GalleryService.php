<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Domain\GalleryStatus;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;

final class GalleryService {
	public function __construct(
		private GalleryMapper $mapper,
		private FolderService $folders,
		private GalleryAccessService $access,
		private PublicShareService $shares,
		private ITimeFactory $clock,
		private IJobList $jobs,
		private LifecycleScheduleService $lifecycleSchedule,
		private GalleryListProjectionService $listProjection,
		private RetentionHandoffService $retention,
		private GalleryMediaCountService $mediaCounts,
		private GalleryCreationService $creation,
		private GalleryReadService $read,
		private GalleryInputValidator $input,
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
		return $this->creation->create($ownerUid, $title, $folderId, $settings, $sourceType, $purpose, $deliveryMode);
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
		return $this->creation->createProject($ownerUid, $title, $purpose, $sourceMode, $folderId, $parentFolderId, $folderName, $settings, $designPreset, $deliveryMode);
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
		return $this->read->list($ownerUid, $limit, $offset, $archived, $search, $sourceType, $ownedOnly);
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
		return $this->read->listV2($userUid, $limit, $cursor, $archived, $search, $sourceType, $status, $mode, $purpose, $ownedOnly, $sort);
	}

	public function get(string $ownerUid, int $id): Gallery {
		return $this->access->owner($ownerUid, $id);
	}

	public function view(string $userId, int $id): Gallery {
		return $this->access->view($userId, $id);
	}

	public function edit(string $userId, int $id): Gallery {
		return $this->access->edit($userId, $id);
	}

	/** @return array<string, mixed> */
	public function present(string $userId, Gallery $gallery): array {
		return $this->read->present($userId, $gallery);
	}

	/** @return array{sortBy: string, sortDirection: string} */
	public function instanceMediaSort(): array {
		return $this->read->instanceMediaSort();
	}

	public function rebindSource(string $ownerUid, int $id, int $folderId): \OCA\ProofingGallery\Dto\SourceRebindResult {
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
			$gallery->setTitle($this->input->validateTitle($title));
		}
		if ($settings !== null) {
			if ($gallery->getSourceType() === 'collection'
				&& (($settings['allowGuestUploads'] ?? false) === true || ($settings['delivery']['guestUploads'] ?? false) === true)) {
				throw new InvalidArgumentException('Guest uploads are unavailable for collections');
			}
			$current = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$merged = GallerySettings::merge($current, $settings);
			\OCA\ProofingGallery\Domain\MediaSort::assertValid($merged->navigation->sortBy, $merged->navigation->sortDirection, $gallery->getSourceType() === 'collection');
			$this->input->assertPresentationAssets($gallery, $merged, $current);
			$gallery->setSettings(json_encode($merged, JSON_THROW_ON_ERROR));
		}
		$gallery->setUpdatedAt($this->clock->getTime());
		$this->lifecycleSchedule->project($gallery, $this->clock->getTime());
		$this->listProjection->project($gallery);

		return $this->shares->updateGallery($gallery, $revision);
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
		$restored = $this->shares->restore($gallery);
		$this->mediaCounts->queue((int)$restored->getId());
		if ($restored->getSourceType() === 'folder') $this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => (int)$restored->getId()]);
		return $restored;
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
}
