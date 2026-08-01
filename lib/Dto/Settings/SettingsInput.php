<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use InvalidArgumentException;

final class SettingsInput {
	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function object(array $input, string $key): array {
		if (!array_key_exists($key, $input)) return [];
		if (!is_array($input[$key])) throw new InvalidArgumentException($key . ' must be an object');
		return $input[$key];
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function merge(string $name, array $defaults, array $input): array {
		$unknown = array_diff(array_keys($input), array_keys($defaults));
		if ($unknown !== []) throw new InvalidArgumentException('Unknown ' . $name . ' setting: ' . reset($unknown));
		return array_replace($defaults, $input);
	}

	public static function bool(mixed $value, string $key): bool {
		if (!is_bool($value)) throw new InvalidArgumentException($key . ' must be a boolean');
		return $value;
	}

	public static function string(mixed $value, string $key): string {
		if (!is_string($value)) throw new InvalidArgumentException($key . ' must be a string');
		return $value;
	}

	public static function int(mixed $value, string $key): int {
		if (!is_int($value)) throw new InvalidArgumentException($key . ' must be an integer');
		return $value;
	}

	public static function nullableInt(mixed $value, string $key): ?int {
		if ($value !== null && !is_int($value)) throw new InvalidArgumentException($key . ' must be a file ID or null');
		return $value;
	}

	/** @param list<string> $allowed */
	public static function choice(mixed $value, string $key, array $allowed): string {
		$value = self::string($value, $key);
		if (!in_array($value, $allowed, true)) throw new InvalidArgumentException('Invalid ' . $key);
		return $value;
	}
}
