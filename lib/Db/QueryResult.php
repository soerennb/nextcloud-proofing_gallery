<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\IResult;
use PDO;
use UnexpectedValueException;

final class QueryResult {
	/** @return list<array<string, mixed>> */
	public static function rows(IResult $result): array {
		$rows = [];
		foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$rows[] = self::associativeRow($row);
		}
		return $rows;
	}

	/** @return array<string, mixed>|false */
	public static function row(IResult $result): array|false {
		$row = $result->fetch(PDO::FETCH_ASSOC);
		return $row === false ? false : self::associativeRow($row);
	}

	/** @return list<mixed> */
	public static function column(IResult $result): array {
		$values = [];
		foreach ($result->fetchAll(PDO::FETCH_COLUMN) as $value) $values[] = $value;
		return $values;
	}

	/** @return array<string, mixed> */
	private static function associativeRow(mixed $row): array {
		if (!is_array($row)) throw new UnexpectedValueException('Database result row must be an array');
		$keys = array_map('strval', array_keys($row));
		return array_combine($keys, array_values($row));
	}
}
