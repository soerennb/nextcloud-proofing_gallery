<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class CsvEncoder {
	/** @param list<list<string>> $rows */
	public function encode(array $rows): string {
		return implode('', array_map(static function (array $row): string {
			return implode(',', array_map(
				static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"',
				$row,
			)) . "\r\n";
		}, $rows));
	}
}
