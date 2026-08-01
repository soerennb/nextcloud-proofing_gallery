<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getGalleryId()
 * @method void setGalleryId(int $galleryId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getParentFileId()
 * @method void setParentFileId(int $parentFileId)
 * @method string getRelativePath()
 * @method void setRelativePath(string $relativePath)
 * @method string getSortKey()
 * @method void setSortKey(string $sortKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int getMtime()
 * @method void setMtime(int $mtime)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method int getDepth()
 * @method void setDepth(int $depth)
 * @method string getScanGeneration()
 * @method void setScanGeneration(string $scanGeneration)
 * @method int getSeenAt()
 * @method void setSeenAt(int $seenAt)
 */
final class MediaIndex extends Entity implements \JsonSerializable {
	protected int $galleryId = 0;
	protected int $fileId = 0;
	protected int $parentFileId = 0;
	protected string $relativePath = '';
	protected string $sortKey = '';
	protected string $name = '';
	protected string $mimeType = '';
	protected int $size = 0;
	protected int $mtime = 0;
	protected string $etag = '';
	protected int $depth = 0;
	protected string $scanGeneration = '';
	protected int $seenAt = 0;

	public function __construct() {
		foreach (['galleryId', 'fileId', 'parentFileId', 'size', 'mtime', 'depth', 'seenAt'] as $field) {
			$this->addType($field, Types::BIGINT);
		}
	}

	/** @return array<string, int|string> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getFileId(),
			'parentId' => $this->getParentFileId(),
			'relativePath' => $this->getRelativePath(),
			'name' => $this->getName(),
			'mimeType' => $this->getMimeType(),
			'size' => $this->getSize(),
			'modifiedAt' => $this->getMtime(),
			'etag' => $this->getEtag(),
			'depth' => $this->getDepth(),
		];
	}
}
