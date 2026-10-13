<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

/** Optional owner filters are evaluated before counting and pagination. */
final class CullingFilter {
	/** @param ?list<int> $fileIds */
	public function __construct(public readonly ?int $rating = null, public readonly ?string $pick = null, public readonly ?string $color = null, public readonly ?array $fileIds = null) {
		new \OCA\ProofingGallery\Domain\CullState($rating ?? 0, $color ?? 'none', $pick ?? 'none', 'app');
		if ($fileIds !== null && (count($fileIds) > 200 || array_filter($fileIds, static fn (int $id): bool => $id <= 0) !== [])) throw new \InvalidArgumentException('Invalid media filter');
	}

	public static function fromInput(?int $rating, ?string $pick, ?string $color, ?string $fileIds): self {
		$ids = $fileIds === null ? null : ($fileIds === '' ? [] : explode(',', $fileIds));
		if ($ids !== null) {
			foreach ($ids as $id) if (!ctype_digit((string)$id)) throw new \InvalidArgumentException('Invalid media filter');
			$ids = array_values(array_unique(array_map('intval', $ids)));
			sort($ids);
		}
		return new self($rating, $pick, $color, $ids);
	}

	public function active(): bool { return $this->rating !== null || $this->pick !== null || $this->color !== null || $this->fileIds !== null; }
}
