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
		private GalleryAccessService $access,
		private ITimeFactory $clock,
	) {
	}

	/** @param array<string, mixed> $settings */
	public function create(string $ownerUid, int $folderId, string $title, array $settings = []): Gallery {
		$this->folders->resolveFolder($ownerUid, $folderId);
		$title = $this->validateTitle($title);
		$now = $this->clock->getTime();

		$gallery = new Gallery();
		$gallery->setOwnerUid($ownerUid);
		$gallery->setFolderId($folderId);
		$gallery->setTitle($title);
		$gallery->setSlug($this->uniqueSlug($ownerUid, $title));
		$gallery->setStatus(GalleryStatus::Draft->value);
		$gallery->setSettings(json_encode(GallerySettings::fromArray($settings), JSON_THROW_ON_ERROR));
		$gallery->setCreatedAt($now);
		$gallery->setUpdatedAt($now);

		return $this->mapper->insert($gallery);
	}

	/** @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function list(string $ownerUid, int $limit, int $offset, bool $archived, string $search): array {
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$search = mb_substr(trim($search), 0, 120);

		$all = $this->access->list($ownerUid, $archived, $search);
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
		return [
			...$gallery->jsonSerialize(),
			'permissions' => $this->access->permissions($userId, $gallery),
		];
	}

	/** @param array<string, mixed>|null $settings */
	public function update(string $ownerUid, int $id, ?string $title, ?array $settings): Gallery {
		$gallery = $this->access->edit($ownerUid, $id);
		if ($title !== null) {
			$gallery->setTitle($this->validateTitle($title));
		}
		if ($settings !== null) {
			$current = json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR);
			$gallery->setSettings(json_encode(GallerySettings::fromArray(array_merge($current, $settings)), JSON_THROW_ON_ERROR));
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
