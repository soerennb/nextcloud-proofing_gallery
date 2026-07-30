<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use JsonSerializable;

final readonly class MediaPage implements JsonSerializable {
	/** @param list<MediaItem> $items */
	public function __construct(
		public array $items,
		public int $total,
		public int $limit,
		public int $offset,
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
