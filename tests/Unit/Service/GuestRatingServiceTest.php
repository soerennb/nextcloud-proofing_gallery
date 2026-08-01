<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\GuestRating;
use OCA\ProofingGallery\Service\GuestRatingAggregator;
use PHPUnit\Framework\TestCase;

final class GuestRatingServiceTest extends TestCase {
	public function testSummaryIncludesCorrectDistributionAverageAndPrivateIdentities(): void {
		$summary = (new GuestRatingAggregator())->summarize(91, [
			$this->rating(10, 91, 5, 'pick', 100),
			$this->rating(11, 91, 3, 'reject', 120),
			$this->rating(12, 91, 4, 'pick', 110),
		], [10 => 'Ada', 11 => 'Grace', 12 => 'Lin']);

		self::assertSame(91, $summary['fileId']);
		self::assertSame(3, $summary['count']);
		self::assertSame(4.0, $summary['average']);
		self::assertSame([0, 0, 0, 1, 1, 1], $summary['distribution']);
		self::assertSame(['none' => 0, 'pick' => 2, 'reject' => 1], $summary['picks']);
		self::assertSame(120, $summary['updatedAt']);
		self::assertSame(['Ada', 'Grace', 'Lin'], array_column($summary['individuals'], 'name'));
	}

	private function rating(int $guestId, int $fileId, int $rating, string $pick, int $updatedAt): GuestRating {
		$value = new GuestRating();
		$value->setGuestId($guestId);
		$value->setFileId($fileId);
		$value->setRating($rating);
		$value->setPickState($pick);
		$value->setUpdatedAt($updatedAt);
		return $value;
	}
}
