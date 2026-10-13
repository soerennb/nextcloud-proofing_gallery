<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Db;

use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\IResult;
use PDO;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class QueryResultTest extends TestCase {
	public function testMaterializedRowReleasesSqliteReadLockWhileResultRemainsAlive(): void {
		$this->withSqliteResult(static function (IResult $result, PDO $writer): void {
			self::assertSame(['id' => 1], QueryResult::firstRow($result));
			self::assertSame(2, $writer->exec('UPDATE fixture SET id = id + 10'));
		});
	}

	public function testInvalidRowStillReleasesSqliteReadLock(): void {
		$this->withSqliteResult(static function (IResult $result, PDO $writer): void {
			try {
				QueryResult::firstRow($result);
				self::fail('Invalid row was accepted');
			} catch (UnexpectedValueException) {
				self::assertSame(2, $writer->exec('UPDATE fixture SET id = id + 10'));
			}
		}, true);
	}

	public function testMaterializedScalarReleasesSqliteReadLock(): void {
		$this->withSqliteResult(static function (IResult $result, PDO $writer): void {
			self::assertSame(1, QueryResult::value($result));
			self::assertSame(2, $writer->exec('UPDATE fixture SET id = id + 10'));
		});
	}

	public function testStreamingRowsRemainReadableUntilCallerClosesCursor(): void {
		$this->withSqliteResult(static function (IResult $result, PDO $writer): void {
			self::assertSame(['id' => 1], QueryResult::row($result));
			self::assertSame(['id' => 2], QueryResult::row($result));
			self::assertFalse(QueryResult::row($result));
			$result->closeCursor();
			self::assertSame(2, $writer->exec('UPDATE fixture SET id = id + 10'));
		});
	}

	private function withSqliteResult(callable $assertion, bool $invalid = false): void {
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('SQLite PDO driver unavailable');
		$path = tempnam(sys_get_temp_dir(), 'pg-cursor-');
		try {
			$reader = new PDO('sqlite:' . $path);
			$writer = new PDO('sqlite:' . $path);
			$writer->exec('PRAGMA busy_timeout=0');
			$reader->exec('CREATE TABLE fixture (id INTEGER); INSERT INTO fixture VALUES (1), (2)');
			$statement = $reader->query('SELECT id FROM fixture ORDER BY id');
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function (int $mode) use ($statement, $invalid): mixed {
				$row = $statement->fetch($mode);
				return $invalid ? new \stdClass() : $row;
			});
			$result->method('closeCursor')->willReturnCallback(static fn (): bool => $statement->closeCursor());
			$result->method('fetchOne')->willReturnCallback(static fn (): mixed => $statement->fetchColumn());
			$assertion($result, $writer);
		} finally {
			unset($statement, $result, $reader, $writer);
			unlink($path);
		}
	}
}
