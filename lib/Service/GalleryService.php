<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Domain\GalleryStatus;
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
	) {
	}

	/** @param array<string, mixed> $settings */
	public function create(
		string $ownerUid,
		string $title,
		?int $folderId,
		array $settings = [],
		string $sourceType = 'folder',
	): Gallery {
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
		$gallery->setSettings(json_encode(GallerySettings::merge(
			GallerySettings::fromArray($this->policies->galleryDefaults()),
			$settings,
		), JSON_THROW_ON_ERROR));
		$gallery->setCreatedAt($now);
		$gallery->setUpdatedAt($now);

		try {
			$gallery = $this->mapper->insert($gallery);
			if ($sourceType === 'collection') {
				$this->collections->initialize($gallery);
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
		if ($gallery->getSourceType() === 'collection') {
			return [
				...$gallery->jsonSerialize(),
				'source' => $this->collections->sourceStatus($gallery),
				'mediaSummary' => $this->collections->summary($gallery),
				'permissions' => $permissions,
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
	public function update(string $ownerUid, int $id, ?string $title, ?array $settings): Gallery {
		$gallery = $this->access->edit($ownerUid, $id);
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

		return $this->mapper->update($gallery);
	}

	public function archive(string $ownerUid, int $id): Gallery {
		$gallery = $this->get($ownerUid, $id);
		$now = $this->clock->getTime();
		$gallery->setStatus(GalleryStatus::Archived->value);
		$gallery->setArchivedAt($now);
		$gallery->setUpdatedAt($now);

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
