<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

/** Missing EXIF offsets are interpreted as UTC, independently of PHP's timezone. */
final class CaptureTimestamp {
	public static function parse(mixed $value, mixed $offset = null): ?int {
		if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value > 0 ? (int)$value : null;
		if (!is_string($value)) return null;
		$value = trim($value);
		if (preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
			$value = substr_replace(substr_replace($value, '-', 4, 1), '-', 7, 1);
			$value = str_replace(' ', 'T', $value);
			if (is_string($offset) && preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/D', $offset)) $value .= $offset;
			else $value .= 'Z';
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)?$/D', $value)) return null;
		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
			$errors = \DateTimeImmutable::getLastErrors();
			if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
			return $date->getTimestamp();
		} catch (\Exception) {
			return null;
		}
	}
}
