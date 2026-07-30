<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getGalleryId()
 * @method void setGalleryId(int $galleryId)
 * @method string getPublicId()
 * @method void setPublicId(string $publicId)
 * @method string getSessionHash()
 * @method void setSessionHash(string $sessionHash)
 * @method string getNonceHash()
 * @method void setNonceHash(string $nonceHash)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method ?string getEmailCipher()
 * @method void setEmailCipher(?string $emailCipher)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getLastSeenAt()
 * @method void setLastSeenAt(int $lastSeenAt)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 */
final class Guest extends Entity implements \JsonSerializable {
	protected int $galleryId = 0;
	protected string $publicId = '';
	protected string $sessionHash = '';
	protected string $nonceHash = '';
	protected string $displayName = '';
	protected ?string $emailCipher = null;
	protected int $createdAt = 0;
	protected int $lastSeenAt = 0;
	protected int $expiresAt = 0;

	public function __construct() {
		foreach (['galleryId', 'createdAt', 'lastSeenAt', 'expiresAt'] as $field) {
			$this->addType($field, Types::BIGINT);
		}
	}

	/** @return array{id: string, displayName: string, createdAt: int} */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getPublicId(),
			'displayName' => $this->getDisplayName(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
