<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use JsonSerializable;

final readonly class MediaItem implements JsonSerializable {
	public function __construct(
		public int $id,
		public string $name,
		public string $mimeType,
		public int $size,
		public int $modifiedAt,
		public string $etag,
		public bool $folder,
	) {
	}

	/** @return array<string, bool|int|string> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'mimeType' => $this->mimeType,
			'size' => $this->size,
			'modifiedAt' => $this->modifiedAt,
			'etag' => $this->etag,
			'folder' => $this->folder,
		];
	}
}
