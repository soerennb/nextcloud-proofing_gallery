<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

final class MediaIndexQuery {
	private const SORTS = \OCA\ProofingGallery\Domain\MediaSort::AUTOMATIC;
	private const DIRECTIONS = ['asc', 'desc'];

	public function __construct(
		public readonly int $galleryId,
		public readonly string $ownerUid,
		public readonly int $limit,
		public readonly string $pathPrefix,
		public readonly string $search,
		public readonly string $sortBy,
		public readonly string $sortDirection,
		public readonly int $minOwnerRating,
		public readonly string $sortRevision = '',
	) {
		if ($galleryId <= 0 || $ownerUid === '') throw new \InvalidArgumentException('Invalid media index owner');
		if ($limit < 1 || $limit > 201) throw new \InvalidArgumentException('Invalid media index limit');
		if (!in_array($sortBy, self::SORTS, true) || !in_array($sortDirection, self::DIRECTIONS, true)) {
			throw new \InvalidArgumentException('Invalid media index arrangement');
		}
		if ($minOwnerRating < 0 || $minOwnerRating > 5) throw new \InvalidArgumentException('Invalid owner rating filter');
		if (in_array('..', explode('/', $pathPrefix), true) || str_contains($pathPrefix, "\0")) {
			throw new \InvalidArgumentException('Invalid gallery path');
		}
	}

	public static function fromInput(
		int $galleryId,
		string $ownerUid,
		int $limit,
		string $pathPrefix,
		string $search,
		string $sortBy,
		string $sortDirection,
		int $minOwnerRating,
		string $sortRevision = '',
	): self {
		return new self(
			$galleryId,
			$ownerUid,
			max(1, min(200, $limit)),
			trim($pathPrefix, '/'),
			mb_substr(mb_strtolower(trim($search)), 0, 120),
			$sortBy,
			$sortDirection,
			$minOwnerRating,
			$sortRevision,
		);
	}

	public function cursorScope(bool $legacy = false): string {
		$scope = [(string)$this->galleryId, $this->ownerUid, $this->pathPrefix, $this->search, (string)$this->minOwnerRating];
		if (!$legacy) $scope[] = $this->sortRevision;
		return hash('sha256', implode("\0", $scope));
	}

	public function withLimit(int $limit): self {
		return new self(
			$this->galleryId,
			$this->ownerUid,
			$limit,
			$this->pathPrefix,
			$this->search,
			$this->sortBy,
			$this->sortDirection,
			$this->minOwnerRating,
			$this->sortRevision,
		);
	}
}
