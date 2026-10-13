<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\IResult;
use PDO;
use UnexpectedValueException;

final class QueryResult {
	/** @return list<array<string, mixed>> */
	public static function rows(IResult $result): array {
		try {
			$rows = [];
			foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$rows[] = self::associativeRow($row);
			}
			return $rows;
		} finally {
			$result->closeCursor();
		}
	}

	/** @return array<string, mixed>|false */
	public static function firstRow(IResult $result): array|false {
		try {
			return self::row($result);
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Read one streaming row; the caller owns cursor cleanup.
	 * @return array<string, mixed>|false
	 */
	public static function row(IResult $result): array|false {
		$row = $result->fetch(PDO::FETCH_ASSOC);
		return $row === false ? false : self::associativeRow($row);
	}

	/** @return list<mixed> */
	public static function column(IResult $result): array {
		try {
			$values = [];
			foreach ($result->fetchAll(PDO::FETCH_COLUMN) as $value) $values[] = $value;
			return $values;
		} finally {
			$result->closeCursor();
		}
	}

	public static function value(IResult $result): mixed {
		try {
			return $result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	/** @return array<string, mixed> */
	private static function associativeRow(mixed $row): array {
		if (!is_array($row)) throw new UnexpectedValueException('Database result row must be an array');
		$keys = array_map('strval', array_keys($row));
		return array_combine($keys, array_values($row));
	}
}
