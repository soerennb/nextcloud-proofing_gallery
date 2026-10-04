<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

/** Shared ordering for folder listings, collections, and the database projection. */
final class MediaSort {
	public const AUTOMATIC = ['name', 'capturedAt', 'modified', 'size'];
	public const ALL = [...self::AUTOMATIC, 'collection'];
	public const DIRECTIONS = ['asc', 'desc'];

	public static function assertValid(string $by, string $direction, bool $collection = false): void {
		if (!in_array($by, $collection ? self::ALL : self::AUTOMATIC, true) || !in_array($direction, self::DIRECTIONS, true)) {
			throw new \InvalidArgumentException('Invalid media sort');
		}
	}

	/** Binary, case-insensitive natural key; numeric runs compare by length then value. */
	public static function nameKey(string $name): string {
		$parts = preg_split('/([0-9]+)/', mb_strtolower($name), -1, PREG_SPLIT_DELIM_CAPTURE);
		$key = '';
		foreach ($parts ?: [] as $part) {
			if ($part !== '' && ctype_digit($part)) {
				$number = ltrim($part, '0') ?: '0';
				$key .= '0' . pack('n', strlen($number)) . $number . "\0";
			} else {
				$key .= $part;
			}
		}
		return $key;
	}

	/** @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 */
	public static function compare(array $left, array $right, string $by, string $direction): int {
		if ($by === 'collection') return 0;
		if ($by === 'capturedAt') {
			$missing = (int)(!isset($left['capturedAt'])) <=> (int)(!isset($right['capturedAt']));
			if ($missing !== 0) return $missing;
		}
		$value = static fn (array $item): string|int => match ($by) {
			'modified' => (int)($item['modifiedAt'] ?? 0),
			'size' => (int)($item['size'] ?? 0),
			'capturedAt' => (int)($item['capturedAt'] ?? 0),
			default => self::nameKey((string)$item['name']),
		};
		$result = $by === 'name' ? strcmp((string)$value($left), (string)$value($right)) : $value($left) <=> $value($right);
		if ($result === 0) $result = (int)$left['id'] <=> (int)$right['id'];
		return $direction === 'desc' ? -$result : $result;
	}
}
