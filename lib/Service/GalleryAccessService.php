<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\Manager;
use OCA\ProofingGallery\Db\ManagerMapper;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCP\IGroupManager;
use OCP\IUserManager;

final class GalleryAccessService {
	public function __construct(
		private GalleryMapper $galleries,
		private ManagerMapper $managers,
		private IUserManager $users,
		private IGroupManager $groups,
	) {
	}

	public function view(string $userId, int $galleryId): Gallery {
		$gallery = $this->galleries->find($galleryId);
		if ($gallery->getOwnerUid() !== $userId && $this->effectiveRole($userId, $galleryId) === null) {
			throw new AuthorizationException('Gallery access denied');
		}
		return $gallery;
	}

	public function edit(string $userId, int $galleryId): Gallery {
		$gallery = $this->galleries->find($galleryId);
		if ($gallery->getOwnerUid() !== $userId && $this->effectiveRole($userId, $galleryId) !== 'editor') {
			throw new AuthorizationException('Gallery edit access denied');
		}
		return $gallery;
	}

	public function owner(string $userId, int $galleryId): Gallery {
		$gallery = $this->galleries->find($galleryId);
		if ($gallery->getOwnerUid() !== $userId) {
			throw new AuthorizationException('Only the gallery owner may perform this action');
		}
		return $gallery;
	}

	/** @return array{role: string, canEdit: bool, canManageAccess: bool, canArchive: bool} */
	public function permissions(string $userId, Gallery $gallery): array {
		if ($gallery->getOwnerUid() === $userId) {
			return [
				'role' => 'owner',
				'canEdit' => true,
				'canManageAccess' => true,
				'canArchive' => true,
			];
		}
		$role = $this->effectiveRole($userId, $gallery->getId());
		return [
			'role' => $role ?? 'viewer',
			'canEdit' => $role === 'editor',
			'canManageAccess' => false,
			'canArchive' => false,
		];
	}

	/** @return list<Gallery> */
	public function list(string $userId, bool $archived, string $search): array {
		$items = $this->galleries->findAllOwned($userId, 1000, 0, $archived, $search);
		$seen = array_fill_keys(array_map(static fn (Gallery $gallery): int => $gallery->getId(), $items), true);
		foreach ($this->memberships($userId) as $membership) {
			$galleryId = $membership->getGalleryId();
			if (isset($seen[$galleryId])) {
				continue;
			}
			$gallery = $this->galleries->find($galleryId);
			$isArchived = $gallery->getStatus() === 'archived';
			if ($isArchived !== $archived || ($search !== '' && mb_stripos($gallery->getTitle(), $search) === false)) {
				continue;
			}
			$items[] = $gallery;
			$seen[$galleryId] = true;
		}
		usort($items, static fn (Gallery $left, Gallery $right): int =>
			($right->getUpdatedAt() <=> $left->getUpdatedAt()) ?: ($right->getId() <=> $left->getId()));
		return $items;
	}

	/**
	 * @param array{value: int|string, id: int}|null $cursor
	 * @return array{items: list<Gallery>, total: int, roles: array<int, string>}
	 */
	public function page(string $userId, bool $archived, string $search, ?string $sourceType, ?string $status, ?string $mode, ?string $purpose, bool $ownedOnly, string $sort, ?array $cursor, int $limit): array {
		$memberships = $this->memberships($userId);
		$user = $this->users->get($userId);
		$groupIds = $user === null ? [] : $this->groups->getUserGroupIds($user);
		$page = $this->galleries->findAccessiblePage($userId, $groupIds, $archived, $search, $sourceType, $status, $mode, $purpose, $ownedOnly, $sort, $cursor, $limit);
		$roles = [];
		foreach ($memberships as $membership) {
			$id = $membership->getGalleryId();
			if (($roles[$id] ?? null) !== 'editor') $roles[$id] = $membership->getRole();
		}
		return [...$page, 'roles' => $roles];
	}

	private function effectiveRole(string $userId, int $galleryId): ?string {
		$role = null;
		foreach ($this->memberships($userId) as $membership) {
			if ($membership->getGalleryId() !== $galleryId) {
				continue;
			}
			if ($membership->getRole() === 'editor') {
				return 'editor';
			}
			$role = 'viewer';
		}
		return $role;
	}

	/** @return list<Manager> */
	private function memberships(string $userId): array {
		$user = $this->users->get($userId);
		$groupIds = $user === null ? [] : $this->groups->getUserGroupIds($user);
		return $this->managers->findForUser($userId, $groupIds);
	}
}
