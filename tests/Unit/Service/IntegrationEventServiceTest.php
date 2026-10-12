<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\IntegrationOutboxRepository;
use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCA\ProofingGallery\Service\IntegrationEventService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class IntegrationEventServiceTest extends TestCase {
	public function testEmittedAndPersistedPayloadsRemoveSecretsRecursively(): void {
		$db = $this->database(false);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$expected = ['state' => 'approved', 'nested' => ['round' => 2], 'eventId' => 'event-fixed'];
		$dispatcher->expects(self::once())->method('dispatchTyped')->with(self::callback(static fn (GalleryIntegrationEvent $event): bool => $event->payload === $expected));
		$db->method('getQueryBuilder')->willReturn($this->query($expected, 2));
		$this->service($db, $dispatcher)->emit('review.approved', 7, ['state' => '<b>approved</b>', 'token' => 'secret', 'nested' => ['email' => 'private@example.test', 'round' => 2, 'shareToken' => 'secret'], 'eventId' => 'event-fixed']);
	}

	public function testTransactionalEventsRemainInTheOutboxUntilCommit(): void {
		$db = $this->database(true);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');
		$db->method('getQueryBuilder')->willReturn($this->query(['eventId' => 'event-fixed'], 1));
		$this->service($db, $dispatcher)->emit('review.approved', 7, ['eventId' => 'event-fixed']);
	}

	private function database(bool $transaction): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn($transaction);
		return $db;
	}

	/** @param array<string, mixed> $payload */
	private function query(array $payload, int $writes): IQueryBuilder {
		$query = $this->createMock(IQueryBuilder::class);
		foreach (['insert', 'delete', 'where', 'values'] as $method) $query->method($method)->willReturnSelf();
		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$query->expects(self::exactly($writes))->method('executeStatement');
		$query->method('createNamedParameter')->willReturnCallback(static function (mixed $value) use ($payload): string {
			if (is_string($value) && str_starts_with($value, '{')) self::assertSame($payload, json_decode($value, true, flags: JSON_THROW_ON_ERROR));
			return ':parameter';
		});
		return $query;
	}

	private function service(IDBConnection $db, IEventDispatcher $events): IntegrationEventService {
		return new IntegrationEventService(new IntegrationOutboxRepository($db), $events, $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class), $db);
	}
}
