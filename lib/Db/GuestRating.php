<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getGalleryId()
 * @method void setGalleryId(int $galleryId)
 * @method int getPublicLinkId()
 * @method void setPublicLinkId(int $publicLinkId)
 * @method int getGuestId()
 * @method void setGuestId(int $guestId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getRating()
 * @method void setRating(int $rating)
 * @method string getPickState()
 * @method void setPickState(string $pickState)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
final class GuestRating extends Entity implements \JsonSerializable {
	protected int $galleryId = 0;
	protected int $publicLinkId = 0;
	protected int $guestId = 0;
	protected int $fileId = 0;
	protected int $rating = 0;
	protected string $pickState = 'none';
	protected int $updatedAt = 0;

	public function __construct() {
		foreach (['galleryId', 'publicLinkId', 'guestId', 'fileId', 'updatedAt'] as $field) $this->addType($field, Types::BIGINT);
		$this->addType('rating', Types::INTEGER);
	}

	/** @return array{fileId: int, rating: int, pick: string, updatedAt: int} */
	public function jsonSerialize(): array {
		return [
			'fileId' => $this->getFileId(),
			'rating' => $this->getRating(),
			'pick' => $this->getPickState(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
