<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\GalleryAttentionRepository;
use OCA\ProofingGallery\Service\GalleryAttentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class GalleryAttentionServiceTest extends TestCase {
	private const NOW = 1_793_000_000;

	public function testClientResultsAndMissedDeadlinesNeedAttention(): void {
		$rows = $this->createMock(GalleryAttentionRepository::class);
		$rows->method('openSelections')->willReturn([1 => ['count' => 2, 'latest' => self::NOW - 600]]);
		$rows->method('uploadsAwaitingReview')->willReturn([]);
		$rows->method('submittedReviews')->willReturn([]);
		$rows->expects(self::once())->method('overdueReviews')
			->with([1, 2, 3], self::callback(static fn (string $today): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1))
			->willReturn([2 => ['count' => 1, 'latest' => self::NOW - 86400]]);
		$rows->expects(self::once())->method('recentGuestComments')->with([1, 2, 3], self::NOW - 7 * 86400)
			->willReturn([3 => ['count' => 4, 'latest' => self::NOW - 60]]);
		$rows->method('guestFeedback')->willReturn([1 => ['count' => 9, 'latest' => self::NOW - 30]]);
		$rows->method('expiringLinks')->willReturn([]);

		$summaries = $this->service($rows)->summaries([1, 2, 3]);

		self::assertTrue($summaries[1]['needsAttention']);
		self::assertSame(2, $summaries[1]['openSelections']);
		self::assertSame(self::NOW - 30, $summaries[1]['lastClientActivityAt']);
		self::assertTrue($summaries[2]['needsAttention']);
		self::assertSame(1, $summaries[2]['overdueReviews']);
		self::assertNull($summaries[2]['lastClientActivityAt']);
		self::assertFalse($summaries[3]['needsAttention'], 'Comments alone are activity, not an open task');
		self::assertSame(4, $summaries[3]['recentComments']);
	}

	public function testExpiringLinksNeedAttentionAndReportTheEarliestExpiry(): void {
		$rows = $this->createMock(GalleryAttentionRepository::class);
		foreach (['openSelections', 'uploadsAwaitingReview', 'submittedReviews', 'overdueReviews', 'recentGuestComments', 'guestFeedback'] as $method) {
			$rows->method($method)->willReturn([]);
		}
		$rows->expects(self::once())->method('expiringLinks')
			->with([5], self::callback(static fn (\DateTimeImmutable $from): bool => $from->getTimestamp() === self::NOW), self::callback(static fn (\DateTimeImmutable $until): bool => $until->getTimestamp() === self::NOW + 7 * 86400))
			->willReturn([5 => ['count' => 1, 'latest' => self::NOW + 3600]]);

		$summary = $this->service($rows)->summaries([5])[5];

		self::assertTrue($summary['needsAttention']);
		self::assertSame(self::NOW + 3600, $summary['nextLinkExpiryAt']);
	}

	public function testEmptyPagesDoNotQuery(): void {
		$rows = $this->createMock(GalleryAttentionRepository::class);
		$rows->expects(self::never())->method('openSelections');
		self::assertSame([], $this->service($rows)->summaries([]));
	}

	private function service(GalleryAttentionRepository $rows): GalleryAttentionService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		return new GalleryAttentionService($rows, $time);
	}
}
