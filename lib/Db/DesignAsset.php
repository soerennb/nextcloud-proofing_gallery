<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getPublicId()
 * @method void setPublicId(string $publicId)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getStorageKey()
 * @method void setStorageKey(string $storageKey)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int getWidth()
 * @method void setWidth(int $width)
 * @method int getHeight()
 * @method void setHeight(int $height)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
final class DesignAsset extends Entity implements \JsonSerializable {
	protected string $publicId = '';
	protected string $ownerUid = '';
	protected string $kind = '';
	protected string $name = '';
	protected string $storageKey = '';
	protected string $mimeType = '';
	protected int $size = 0;
	protected int $width = 0;
	protected int $height = 0;
	protected int $createdAt = 0;

	public function __construct() {
		foreach (['size', 'createdAt'] as $field) $this->addType($field, Types::BIGINT);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getPublicId(), 'kind' => $this->getKind(), 'name' => $this->getName(), 'mimeType' => $this->getMimeType(),
			'size' => $this->getSize(), 'width' => $this->getWidth(), 'height' => $this->getHeight(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
