<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Domain\MediaSort;
use OCA\ProofingGallery\Service\CaptureTimestamp;
use PHPUnit\Framework\TestCase;

final class MediaSortingTest extends TestCase {
	public function testNaturalNamesIgnoreCaseAndPathsAndTieByFileId(): void {
		$items = [
			['id' => 9, 'name' => 'IMG10.jpg'],
			['id' => 7, 'name' => 'img02.jpg'],
			['id' => 2, 'name' => 'IMG2.jpg'],
			['id' => 1, 'name' => 'img1.jpg'],
		];
		usort($items, static fn (array $a, array $b): int => MediaSort::compare($a, $b, 'name', 'asc'));
		self::assertSame([1, 2, 7, 9], array_column($items, 'id'));
		usort($items, static fn (array $a, array $b): int => MediaSort::compare($a, $b, 'name', 'desc'));
		self::assertSame([9, 7, 2, 1], array_column($items, 'id'));
	}

	public function testUnknownDatesStayLastInBothDirections(): void {
		$items = [['id' => 1], ['id' => 2, 'capturedAt' => 100], ['id' => 3, 'capturedAt' => 200], ['id' => 4, 'capturedAt' => null]];
		foreach (['asc' => [2, 3, 1, 4], 'desc' => [3, 2, 4, 1]] as $direction => $expected) {
			usort($items, static fn (array $a, array $b): int => MediaSort::compare($a, $b, 'capturedAt', $direction));
			self::assertSame($expected, array_column($items, 'id'));
		}
	}

	public function testExifOffsetsAndTimezoneLessDatesNormalizeToUtc(): void {
		self::assertSame(1704106800, CaptureTimestamp::parse('2024:01:01 12:00:00', '+01:00'));
		self::assertSame(1704106800, CaptureTimestamp::parse('2024-01-01T12:00:00+01:00'));
		$previous = date_default_timezone_get();
		try {
			date_default_timezone_set('America/New_York');
			self::assertSame(1704110400, CaptureTimestamp::parse('2024:01:01 12:00:00'));
			self::assertSame(1704110400, CaptureTimestamp::parse('2024-01-01T12:00:00'));
		} finally { date_default_timezone_set($previous); }
	}

	public function testPhotosMtimeFallbackIsNotACaptureDate(): void {
		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getMTime')->willReturn(1704110400);
		$file->method('getSize')->willReturn(0);
		$extractor = new \OCA\ProofingGallery\Service\EmbeddedMetadataExtractor(new \OCA\ProofingGallery\Service\PolicyService($this->createMock(\OCP\IConfig::class)));
		self::assertArrayNotHasKey('capturedAt', $extractor->extract($file, ['photos-original_date_time' => 1704110400]));
		self::assertSame(1704106800, $extractor->extract($file, ['photos-original_date_time' => 1704106800])['capturedAt']);
		self::assertSame(1704110400, $extractor->extract($file, ['photos-exif' => ['DateTimeOriginal' => '2024:01:01 12:00:00'], 'photos-original_date_time' => 1704110400])['capturedAt']);
	}

	public function testInvalidDatesRemainUnknown(): void {
		foreach ([null, '', 'yesterday', '2024:02:31 12:00:00', '2024-13-01T12:00:00Z', 0] as $value) self::assertNull(CaptureTimestamp::parse($value));
	}
}
