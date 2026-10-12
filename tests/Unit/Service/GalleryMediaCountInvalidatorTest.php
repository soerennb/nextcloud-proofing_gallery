<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\BackgroundJob\RebuildGalleryMediaCountsJob;
use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Service\GalleryMediaCountInvalidator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class GalleryMediaCountInvalidatorTest extends TestCase {
	public function testLargeInvalidationsIncludeCollectionsWithBoundedQueriesAndJobs(): void {
		$db = $this->createMock(IDBConnection::class);
		$writes = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$writes): IQueryBuilder {
			$query = $this->createMock(IQueryBuilder::class);
			$table = ''; $ids = [];
			foreach (['select', 'selectDistinct', 'set', 'where'] as $method) $query->method($method)->willReturnSelf();
			foreach (['from', 'update'] as $method) $query->method($method)->willReturnCallback(static function (string $value) use (&$table, $query): IQueryBuilder { $table = $value; return $query; });
			$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
			$query->method('createNamedParameter')->willReturnCallback(static function (mixed $value) use (&$ids): string {
				if (is_array($value)) { self::assertLessThanOrEqual(500, count($value)); $ids = $value; }
				return ':parameter';
			});
			$query->method('executeQuery')->willReturnCallback(function () use (&$table, &$ids): IResult {
				$result = $this->createMock(IResult::class);
				$rows = $table === 'proofing_collection_items' ? (in_array(1, $ids, true) ? [2001] : []) : array_map(static fn (int $id): array => ['gallery_id' => $id], $ids);
				$result->method('fetchAll')->willReturn($rows);
				return $result;
			});
			$query->method('executeStatement')->willReturnCallback(static function () use (&$writes, &$table): int { self::assertSame('proofing_media_counts', $table); $writes++; return 1; });
			return $query;
		});
		$jobs = $this->createMock(IJobList::class);
		$queued = [];
		$jobs->expects(self::exactly(3))->method('add')->willReturnCallback(static function (string $class, array $argument) use (&$queued): void {
			self::assertSame(RebuildGalleryMediaCountsJob::class, $class);
			self::assertLessThanOrEqual(500, count($argument['galleryIds']));
			$queued = [...$queued, ...$argument['galleryIds']];
		});
		$invalidator = new GalleryMediaCountInvalidator(new GalleryMediaCountRepository($db), new CollectionRepository($db), $jobs, $this->createMock(ITimeFactory::class));
		$invalidator->invalidateMany(range(1, 1200));
		self::assertSame(3, $writes);
		self::assertSame([...range(1, 1200), 2001], $queued);
	}
}
