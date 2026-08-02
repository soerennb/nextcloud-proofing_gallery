<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\File;

final class EmbeddedMetadataExtractor {
	public function __construct(private PolicyService $policies) {
	}

	/** @param array<string, mixed> $known
	 * @return array<string, mixed>
	 */
	public function extract(File $file, array $known): array {
		$flat = $this->flatten($known);
		$details = [
			'capturedAt' => $this->timestamp($this->first($flat, ['photos-original_date_time', 'datetimeoriginal', 'date_time_original'])),
			'camera' => $this->joined($this->first($flat, ['make']), $this->first($flat, ['model', 'cameramodelname'])),
			'lens' => $this->text($this->first($flat, ['lensmodel', 'lens'])),
			'focalLength' => $this->number($this->first($flat, ['focallength'])),
			'aperture' => $this->number($this->first($flat, ['fnumber', 'aperturevalue'])),
			'exposureTime' => $this->text($this->first($flat, ['exposuretime'])),
			'iso' => $this->integer($this->first($flat, ['isospeedratings', 'photographicsensitivity', 'iso'])),
			'width' => $this->integer($this->first($flat, ['width', 'exifimagewidth', 'pixelxdimension'])),
			'height' => $this->integer($this->first($flat, ['height', 'exifimagelength', 'pixelydimension'])),
			'gps' => $this->gps($flat),
		];
		$embedded = $this->fromFile($file);
		return array_filter(array_replace($details, array_filter(
			$embedded,
			static fn (mixed $value): bool => $value !== null && $value !== [],
		)), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
	}

	/** @return array<string, mixed> */
	private function fromFile(File $file): array {
		$maxBytes = $this->policies->get('metadataMaxBytes');
		if ($file->getSize() <= 0 || $file->getSize() > $maxBytes) return [];
		$temporaryPath = tempnam(sys_get_temp_dir(), 'proofing-meta-');
		if ($temporaryPath === false) return [];
		$input = $file->fopen('rb');
		$output = fopen($temporaryPath, 'wb');
		if (!is_resource($input) || !is_resource($output)) {
			if (is_resource($input)) fclose($input);
			if (is_resource($output)) fclose($output);
			@unlink($temporaryPath);
			return [];
		}
		try {
			$copied = stream_copy_to_stream($input, $output, $maxBytes + 1);
		} finally {
			fclose($input);
			fclose($output);
		}
		if ($copied === false || $copied > $maxBytes) {
			@unlink($temporaryPath);
			return [];
		}
		try {
			$raw = function_exists('exif_read_data') ? @exif_read_data($temporaryPath, null, true, false) : false;
			$info = [];
			$dimensions = @getimagesize($temporaryPath, $info);
			$flat = is_array($raw) ? $this->flatten($raw) : [];
			$iptc = isset($info['APP13']) && function_exists('iptcparse') ? @iptcparse($info['APP13']) : false;
			return [
				'capturedAt' => $this->timestamp($this->first($flat, ['datetimeoriginal', 'date_time_original'])),
				'camera' => $this->joined($this->first($flat, ['make']), $this->first($flat, ['model'])),
				'lens' => $this->text($this->first($flat, ['lensmodel', 'lens'])),
				'focalLength' => $this->number($this->first($flat, ['focallength'])),
				'aperture' => $this->number($this->first($flat, ['fnumber'])),
				'exposureTime' => $this->text($this->first($flat, ['exposuretime'])),
				'iso' => $this->integer($this->first($flat, ['isospeedratings', 'photographicsensitivity'])),
				'width' => is_array($dimensions) ? (int)$dimensions[0] : null,
				'height' => is_array($dimensions) ? (int)$dimensions[1] : null,
				'gps' => $this->gps($flat),
				'title' => $this->iptcFirst($iptc, '2#005'),
				'description' => $this->iptcFirst($iptc, '2#120'),
				'creator' => $this->iptcFirst($iptc, '2#080'),
				'copyright' => $this->iptcFirst($iptc, '2#116'),
				'keywords' => $this->iptcList($iptc, '2#025'),
			];
		} finally {
			@unlink($temporaryPath);
		}
	}

	/** @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function flatten(array $input): array {
		$result = [];
		array_walk_recursive($input, static function (mixed $value, string|int $key) use (&$result): void {
			if (is_string($key)) $result[mb_strtolower(str_replace([' ', '-', '_'], '', $key))] = $value;
		});
		foreach ($input as $key => $value) if (is_string($key)) $result[mb_strtolower(str_replace([' ', '-', '_'], '', $key))] = $value;
		return $result;
	}

	/** @param array<string, mixed> $values
	 * @param list<string> $keys
	 */
	private function first(array $values, array $keys): mixed {
		foreach ($keys as $key) {
			$normalized = mb_strtolower(str_replace([' ', '-', '_'], '', $key));
			if (array_key_exists($normalized, $values)) return $values[$normalized];
		}
		return null;
	}

	private function timestamp(mixed $value): ?int {
		if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
		if (!is_string($value) || trim($value) === '') return null;
		$date = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', trim($value));
		return $date === false ? null : $date->getTimestamp();
	}

	private function number(mixed $value): ?float {
		if (is_int($value) || is_float($value)) return (float)$value;
		if (!is_string($value)) return null;
		if (preg_match('/^(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)$/', trim($value), $matches) === 1 && (float)$matches[2] !== 0.0) return (float)$matches[1] / (float)$matches[2];
		return is_numeric($value) ? (float)$value : null;
	}

	private function integer(mixed $value): ?int {
		if (is_array($value)) $value = reset($value);
		return is_int($value) || (is_string($value) && is_numeric($value)) ? (int)$value : null;
	}

	/** @param array<string, mixed> $values
	 * @return ?array{latitude: float, longitude: float}
	 */
	private function gps(array $values): ?array {
		$latitude = $this->coordinate($this->first($values, ['gpslatitude']), $this->text($this->first($values, ['gpslatituderef'])));
		$longitude = $this->coordinate($this->first($values, ['gpslongitude']), $this->text($this->first($values, ['gpslongituderef'])));
		if ($latitude === null || $longitude === null || abs($latitude) > 90 || abs($longitude) > 180) return null;
		return ['latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
	}

	private function coordinate(mixed $parts, ?string $reference): ?float {
		if (!is_array($parts) || count($parts) < 3) return null;
		$degrees = $this->number($parts[0]);
		$minutes = $this->number($parts[1]);
		$seconds = $this->number($parts[2]);
		if ($degrees === null || $minutes === null || $seconds === null) return null;
		$value = $degrees + ($minutes / 60) + ($seconds / 3600);
		return in_array(mb_strtoupper((string)$reference), ['S', 'W'], true) ? -$value : $value;
	}

	private function text(mixed $value): ?string {
		if (is_array($value)) $value = reset($value);
		if (!is_scalar($value)) return null;
		$text = trim((string)$value);
		return $text === '' ? null : mb_substr($text, 0, 500);
	}

	private function joined(mixed ...$values): ?string {
		$parts = array_values(array_unique(array_filter(array_map(fn (mixed $value): ?string => $this->text($value), $values))));
		return $parts === [] ? null : implode(' ', $parts);
	}

	/** @param array<string, list<mixed>>|false $iptc */
	private function iptcFirst(array|false $iptc, string $key): ?string {
		return is_array($iptc) ? $this->text($iptc[$key][0] ?? null) : null;
	}

	/** @param array<string, list<mixed>>|false $iptc
	 * @return list<string>
	 */
	private function iptcList(array|false $iptc, string $key): array {
		if (!is_array($iptc) || !is_array($iptc[$key] ?? null)) return [];
		return array_values(array_unique(array_filter(array_map(fn (mixed $value): ?string => $this->text($value), $iptc[$key]))));
	}
}
