<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

final class PublicGalleryQuery {
	public function __construct(
		public readonly int $limit = 60,
		public readonly int $offset = 0,
		public readonly string $path = '',
		public readonly string $search = '',
		public readonly string $sortBy = '',
		public readonly string $sortDirection = '',
		public readonly string $groupBy = '',
		public readonly ?string $cursor = null,
	) {
	}
}
