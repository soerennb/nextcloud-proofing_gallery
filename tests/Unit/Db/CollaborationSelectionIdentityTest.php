<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Db;

use OCA\ProofingGallery\Db\CollaborationRepository;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class CollaborationSelectionIdentityTest extends TestCase {
	public function testAccountSelectionRemainsScopedToAccountAndLink(): void {
		$this->assertSelectionScope(null, 'reviewer', 's.actor_uid', 'reviewer');
	}

	public function testGuestSelectionRemainsScopedToGuestAndLink(): void {
		$this->assertSelectionScope(42, null, 's.guest_id', 42);
	}

	public function testMissingIdentityCannotReadAnotherReviewersSelection(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$this->expectException(\InvalidArgumentException::class);
		(new CollaborationRepository($db, $this->createMock(IUserManager::class)))->latestSelectionForLink(7, 9, null);
	}

	private function assertSelectionScope(?int $guestId, ?string $uid, string $column, int|string $value): void {
		$db = $this->createMock(IDBConnection::class);
		$query = $this->createMock(IQueryBuilder::class);
		$db->method('getQueryBuilder')->willReturn($query);
		foreach (['select', 'from', 'leftJoin', 'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults'] as $method) {
			$query->method($method)->willReturnSelf();
		}
		$query->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR));
		$expressions = $query->expr();
		$comparisons = [];
		$expressions->method('eq')->willReturnCallback(static function (string $left, mixed $right) use (&$comparisons): string {
			$comparisons[$left] = $right;
			return $left . ' = ' . $right;
		});
		$query->method('expr')->willReturn($expressions);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(['id' => 11, 'item_count' => 2]);
		$query->method('executeQuery')->willReturn($result);
		$repository = new CollaborationRepository($db, $this->createMock(IUserManager::class));
		self::assertSame(11, $repository->latestSelectionForLink(7, 9, $guestId, $uid)['id']);
		self::assertSame('7', $comparisons['s.gallery_id']);
		self::assertSame('9', $comparisons['s.public_link_id']);
		self::assertSame(json_encode($value, JSON_THROW_ON_ERROR), $comparisons[$column]);
		self::assertArrayNotHasKey($guestId === null ? 's.guest_id' : 's.actor_uid', $comparisons);
	}
}
