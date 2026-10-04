<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Db;

use OCA\ProofingGallery\Db\MediaIndex;
use OCA\ProofingGallery\Domain\MediaSort;
use PHPUnit\Framework\TestCase;

final class MediaIndexTest extends TestCase {
	public function testRowsAwaitingBackfillRemainReadable(): void {
		$entry = MediaIndex::fromRow(['file_id' => 7, 'name' => 'IMG02.jpg', 'natural_name' => null, 'captured_at' => null]);
		self::assertSame(MediaSort::nameKey('IMG02.jpg'), $entry->getNaturalName());
		self::assertNull($entry->getCapturedAt());
		self::assertArrayNotHasKey('capturedAt', $entry->jsonSerialize());
		self::assertArrayNotHasKey('naturalName', $entry->jsonSerialize());
	}

	public function testDatabaseBinaryStreamsKeepTheirNaturalKey(): void {
		$key = MediaSort::nameKey('IMG02.jpg');
		$stream = fopen('php://memory', 'w+b');
		self::assertIsResource($stream);
		try {
			fwrite($stream, $key);
			rewind($stream);
			$entry = MediaIndex::fromRow(['file_id' => 7, 'name' => 'IMG02.jpg', 'natural_name' => $stream]);
			self::assertSame($key, $entry->getNaturalName());
		} finally { fclose($stream); }
	}
}
