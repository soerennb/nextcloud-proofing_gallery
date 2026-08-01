<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use JsonSerializable;

final class MediaItem implements JsonSerializable {
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $mimeType,
		public readonly int $size,
		public readonly int $modifiedAt,
		public readonly string $etag,
		public readonly bool $folder,
		public readonly array $metadata = ['state' => 'unavailable'],
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
			'metadata' => $this->metadata,
		];
	}
}
