<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

final class CullState {
	private const COLORS = ['none', 'red', 'yellow', 'green', 'blue', 'purple'];
	private const PICKS = ['none', 'pick', 'reject'];
	private const SOURCES = ['app', 'xmp', 'merge'];

	public function __construct(
		public readonly int $rating,
		public readonly string $color,
		public readonly string $pick,
		public readonly string $source,
	) {
		if ($rating < 0 || $rating > 5
			|| !in_array($color, self::COLORS, true)
			|| !in_array($pick, self::PICKS, true)
			|| !in_array($source, self::SOURCES, true)) {
			throw new \InvalidArgumentException('Invalid culling value');
		}
	}
}
