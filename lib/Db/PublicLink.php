<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getGalleryId()
 * @method void setGalleryId(int $galleryId)
 * @method ?int getCoreShareId()
 * @method void setCoreShareId(?int $coreShareId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method bool getIsPrimary()
 * @method void setIsPrimary(bool $isPrimary)
 * @method string getPolicy()
 * @method void setPolicy(string $policy)
 * @method string getStartPath()
 * @method void setStartPath(string $startPath)
 * @method string getViewMode()
 * @method void setViewMode(string $viewMode)
 * @method int getGroupDepth()
 * @method void setGroupDepth(int $groupDepth)
 * @method int getMinOwnerRating()
 * @method void setMinOwnerRating(int $minOwnerRating)
 * @method ?string getPublicLocale()
 * @method void setPublicLocale(?string $publicLocale)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method ?int getRevokedAt()
 * @method void setRevokedAt(?int $revokedAt)
 */
final class PublicLink extends Entity implements \JsonSerializable {
	protected int $galleryId = 0;
	protected ?int $coreShareId = null;
	protected string $token = '';
	protected string $name = '';
	protected string $status = 'active';
	protected bool $isPrimary = false;
	protected string $policy = '{}';
	// A sentinel ensures Entity marks an explicitly configured root path (the
	// empty string) dirty for inserts on schemas upgraded before its DB default.
	protected string $startPath = "\0";
	protected string $viewMode = 'folder';
	protected int $groupDepth = 0;
	protected int $minOwnerRating = 0;
	protected ?string $publicLocale = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $revokedAt = null;

	public function __construct() {
		foreach (['galleryId', 'coreShareId', 'groupDepth', 'minOwnerRating', 'createdAt', 'updatedAt', 'revokedAt'] as $field) $this->addType($field, Types::BIGINT);
		$this->addType('isPrimary', Types::BOOLEAN);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'galleryId' => $this->getGalleryId(),
			'name' => $this->getName(),
			'status' => $this->getStatus(),
			'primary' => $this->getIsPrimary(),
			'policy' => json_decode($this->getPolicy(), true, flags: JSON_THROW_ON_ERROR),
			'startPath' => $this->getStartPath(),
			'viewMode' => $this->getViewMode(),
			'groupDepth' => $this->getGroupDepth(),
			'minOwnerRating' => $this->getMinOwnerRating(),
			'publicLocale' => $this->getPublicLocale(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
			'revokedAt' => $this->getRevokedAt(),
		];
	}
}
