<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getGalleryId()
 * @method void setGalleryId(int $galleryId)
 * @method string getPrincipalType()
 * @method void setPrincipalType(string $principalType)
 * @method string getUserUid()
 * @method void setUserUid(string $userUid)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
final class Manager extends Entity implements \JsonSerializable {
	protected int $galleryId = 0;
	protected string $principalType = 'user';
	protected string $userUid = '';
	protected string $role = 'viewer';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('galleryId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
	}

	/** @return array<string, int|string> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'type' => $this->getPrincipalType(),
			'principalId' => $this->getUserUid(),
			'role' => $this->getRole(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
