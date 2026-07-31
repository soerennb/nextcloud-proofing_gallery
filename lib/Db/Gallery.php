<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method int getFolderId()
 * @method void setFolderId(int $folderId)
 * @method string getSourceType()
 * @method void setSourceType(string $sourceType)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getSettings()
 * @method void setSettings(string $settings)
 * @method ?string getShareToken()
 * @method void setShareToken(?string $shareToken)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method ?int getArchivedAt()
 * @method void setArchivedAt(?int $archivedAt)
 */
final class Gallery extends Entity implements \JsonSerializable {
	protected string $ownerUid = '';
	protected int $folderId = 0;
	protected string $sourceType = 'folder';
	protected string $title = '';
	protected string $slug = '';
	protected string $status = 'draft';
	protected string $settings = '{}';
	protected ?string $shareToken = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $archivedAt = null;

	public function __construct() {
		$this->addType('folderId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('archivedAt', Types::BIGINT);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ownerUid' => $this->getOwnerUid(),
			'folderId' => $this->getFolderId(),
			'sourceType' => $this->getSourceType(),
			'title' => $this->getTitle(),
			'slug' => $this->getSlug(),
			'status' => $this->getStatus(),
			'settings' => GallerySettings::fromArray(
				json_decode($this->getSettings(), true, flags: JSON_THROW_ON_ERROR),
			),
			'shareToken' => $this->getShareToken(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
			'archivedAt' => $this->getArchivedAt(),
		];
	}
}
