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
 * @method ?int getScopeAnchorId()
 * @method void setScopeAnchorId(?int $scopeAnchorId)
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
 * @method ?string getAllowedRoots()
 * @method void setAllowedRoots(?string $allowedRoots)
 * @method string getScopeMode()
 * @method void setScopeMode(string $scopeMode)
 * @method string getViewMode()
 * @method void setViewMode(string $viewMode)
 * @method int getGroupDepth()
 * @method void setGroupDepth(int $groupDepth)
 * @method int getMinOwnerRating()
 * @method void setMinOwnerRating(int $minOwnerRating)
 * @method ?string getPublicLocale()
 * @method void setPublicLocale(?string $publicLocale)
 * @method bool getReviewEnabled()
 * @method void setReviewEnabled(bool $reviewEnabled)
 * @method ?string getReviewDueDate()
 * @method void setReviewDueDate(?string $reviewDueDate)
 * @method ?int getReviewSelectionMin()
 * @method void setReviewSelectionMin(?int $reviewSelectionMin)
 * @method ?int getReviewSelectionMax()
 * @method void setReviewSelectionMax(?int $reviewSelectionMax)
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
	protected ?int $scopeAnchorId = null;
	protected string $token = '';
	protected string $name = '';
	protected string $status = 'active';
	protected bool $isPrimary = false;
	protected string $policy = '{}';
	protected string $startPath = '';
	protected ?string $allowedRoots = null;
	protected string $scopeMode = 'legacy';
	protected string $viewMode = 'folder';
	protected int $groupDepth = 0;
	protected int $minOwnerRating = 0;
	protected ?string $publicLocale = null;
	protected bool $reviewEnabled = false;
	protected ?string $reviewDueDate = null;
	protected ?int $reviewSelectionMin = null;
	protected ?int $reviewSelectionMax = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $revokedAt = null;

	public function __construct() {
		foreach (['galleryId', 'coreShareId', 'scopeAnchorId', 'createdAt', 'updatedAt', 'revokedAt'] as $field) $this->addType($field, Types::BIGINT);
		foreach (['groupDepth', 'minOwnerRating'] as $field) $this->addType($field, Types::INTEGER);
		$this->addType('isPrimary', Types::BOOLEAN);
		$this->addType('reviewEnabled', Types::BOOLEAN);
		$this->addType('reviewSelectionMin', Types::INTEGER);
		$this->addType('reviewSelectionMax', Types::INTEGER);
	}

	/**
	 * The database cannot reliably provide a default for TEXT columns. Unlike
	 * Entity's magic setter, this method also marks an empty root path dirty.
	 */
	public function setStartPath(string $startPath): void {
		$this->markFieldUpdated('startPath');
		$this->startPath = $startPath;
	}

	/** @param list<string> $roots */
	public function setAllowedRootList(array $roots): void {
		$this->setAllowedRoots($roots === [] ? null : json_encode(array_values($roots), JSON_THROW_ON_ERROR));
	}

	/** @return list<string> */
	public function allowedRootList(): array {
		if ($this->getAllowedRoots() === null || $this->getAllowedRoots() === '') return [];
		$roots = json_decode($this->getAllowedRoots(), true, flags: JSON_THROW_ON_ERROR);
		return is_array($roots) ? array_values(array_filter($roots, 'is_string')) : [];
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
			'allowedRoots' => $this->allowedRootList(),
			'scopeMode' => $this->getScopeMode(),
			'viewMode' => $this->getViewMode(),
			'groupDepth' => $this->getGroupDepth(),
			'minOwnerRating' => $this->getMinOwnerRating(),
			'publicLocale' => $this->getPublicLocale(),
			'reviewEnabled' => $this->getReviewEnabled(),
			'reviewDueDate' => $this->getReviewDueDate(),
			'reviewSelectionMinimum' => $this->getReviewSelectionMin(),
			'reviewSelectionMaximum' => $this->getReviewSelectionMax(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
			'revokedAt' => $this->getRevokedAt(),
		];
	}
}
