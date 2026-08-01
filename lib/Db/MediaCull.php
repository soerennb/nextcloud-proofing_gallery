<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getRating()
 * @method void setRating(int $rating)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method string getPickState()
 * @method void setPickState(string $pickState)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method ?string getSourceEtag()
 * @method void setSourceEtag(?string $sourceEtag)
 * @method ?string getSidecarEtag()
 * @method void setSidecarEtag(?string $sidecarEtag)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
final class MediaCull extends Entity implements \JsonSerializable {
	protected string $ownerUid = '';
	protected int $fileId = 0;
	protected int $rating = 0;
	protected string $color = 'none';
	protected string $pickState = 'none';
	protected string $source = 'app';
	protected int $revision = 1;
	protected ?string $sourceEtag = null;
	protected ?string $sidecarEtag = null;
	protected int $updatedAt = 0;

	public function __construct() {
		foreach (['fileId', 'rating', 'revision', 'updatedAt'] as $field) $this->addType($field, Types::BIGINT);
	}

	/** @return array<string, int|string|null> */
	public function jsonSerialize(): array {
		return [
			'fileId' => $this->getFileId(),
			'rating' => $this->getRating(),
			'color' => $this->getColor(),
			'pick' => $this->getPickState(),
			'source' => $this->getSource(),
			'revision' => $this->getRevision(),
			'sourceEtag' => $this->getSourceEtag(),
			'sidecarEtag' => $this->getSidecarEtag(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
