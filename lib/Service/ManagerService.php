<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Manager;
use OCA\ProofingGallery\Db\ManagerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;

final class ManagerService {
	public function __construct(
		private ManagerMapper $managers,
		private GalleryAccessService $access,
		private IUserManager $users,
		private IGroupManager $groups,
		private ITimeFactory $clock,
	) {
	}

	/** @return list<Manager> */
	public function list(string $ownerUid, int $galleryId): array {
		$this->access->owner($ownerUid, $galleryId);
		return $this->managers->findByGallery($galleryId);
	}

	public function save(string $ownerUid, int $galleryId, string $type, string $principalId, string $role): Manager {
		$this->access->owner($ownerUid, $galleryId);
		if (!in_array($type, ['user', 'group'], true) || !in_array($role, ['viewer', 'editor'], true)) {
			throw new InvalidArgumentException('Invalid principal type or role');
		}
		if (($type === 'user' && !$this->users->userExists($principalId))
			|| ($type === 'group' && !$this->groups->groupExists($principalId))) {
			throw new InvalidArgumentException('User or group does not exist');
		}
		if ($type === 'user' && $principalId === $ownerUid) {
			throw new InvalidArgumentException('The owner already has full access');
		}

		try {
			$manager = $this->managers->findPrincipal($galleryId, $type, $principalId);
			$manager->setRole($role);
			return $this->managers->update($manager);
		} catch (DoesNotExistException) {
			$manager = new Manager();
			$manager->setGalleryId($galleryId);
			$manager->setPrincipalType($type);
			$manager->setUserUid($principalId);
			$manager->setRole($role);
			$manager->setCreatedAt($this->clock->getTime());
			return $this->managers->insert($manager);
		}
	}

	public function remove(string $ownerUid, int $galleryId, int $managerId): void {
		$this->access->owner($ownerUid, $galleryId);
		foreach ($this->managers->findByGallery($galleryId) as $manager) {
			if ($manager->getId() === $managerId) {
				$this->managers->delete($manager);
				return;
			}
		}
		throw new DoesNotExistException('Manager not found');
	}
}
