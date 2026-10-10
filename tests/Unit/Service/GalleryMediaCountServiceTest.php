<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\GalleryMediaCountService;
use PHPUnit\Framework\TestCase;

final class GalleryMediaCountServiceTest extends TestCase {
	public function testUnknownCountsAreNotPresentedAsAnEmptyGallery(): void {
		self::assertSame(['total' => 0, 'imageCount' => null, 'videoCount' => null, 'countState' => 'pending', 'countedAt' => null], GalleryMediaCountService::present(null));
	}

	public function testUpdatesKeepTheCompletedSnapshotRatherThanWorkingCounters(): void {
		$summary = GalleryMediaCountService::present(['state' => 'updating', 'image_count' => '24', 'video_count' => '2', 'working_images' => 100, 'counted_at' => '123']);
		self::assertSame(26, $summary['total']);
		self::assertSame(24, $summary['imageCount']);
		self::assertSame(2, $summary['videoCount']);
		self::assertSame(123, $summary['countedAt']);
	}

	public function testUnavailableSourcesDoNotAdvertisePreviouslyAvailableFiles(): void {
		$summary = GalleryMediaCountService::present(['state' => 'unavailable', 'image_count' => 24, 'video_count' => 2]);
		self::assertSame(0, $summary['total']);
		self::assertNull($summary['imageCount']);
		self::assertNull($summary['videoCount']);
	}
}
