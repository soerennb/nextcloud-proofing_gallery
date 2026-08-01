<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use JsonSerializable;

final class MediaPage implements JsonSerializable {
	/** @param list<MediaItem> $items */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $limit,
		public readonly int $offset,
	) {
	}

	/** @return array{items: list<MediaItem>, total: int, limit: int, offset: int} */
	public function jsonSerialize(): array {
		return [
			'items' => $this->items,
			'total' => $this->total,
			'limit' => $this->limit,
			'offset' => $this->offset,
		];
	}
}
