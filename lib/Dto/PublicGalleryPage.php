<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

/** Source readers return data; the presenter alone owns the wire document. */
final class PublicGalleryPage {
	/** @param list<array<string, mixed>> $items
	 * @param array<string, int> $groups
	 * @param array<string, mixed> $indexState
	 * @param array<string, mixed> $scope */
	public function __construct(public readonly array $items, public readonly int $total, public readonly PublicGalleryQuery $query, public readonly int $offset, public readonly ?string $nextCursor = null, public readonly ?string $previousCursor = null, public readonly array $groups = [], public readonly array $indexState = [], public readonly array $scope = [], public readonly ?int $focusIndex = null) {}
}
