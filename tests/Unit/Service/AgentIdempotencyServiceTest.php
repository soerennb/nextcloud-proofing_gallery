<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\AgentRequestStore;
use OCA\ProofingGallery\Exception\AgentRequestConflictException;
use OCA\ProofingGallery\Service\AgentIdempotencyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AgentIdempotencyServiceTest extends TestCase {
	public function testCompletedRequestReplaysWithoutExecutingTheMutation(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturn(false);
		$store->method('find')->willReturn(['payload_hash' => hash('sha256', '[]'), 'response_json' => '{"id":7}']);
		$service = $this->service($store);
		$result = $service->run('owner', 'gallery.create', 'request-1', [], static fn () => self::fail('Replay must not execute'));
		self::assertSame(['data' => ['id' => 7], 'replayed' => true], $result);
	}

	public function testReleasesOnlyAfterConfirmedDatabaseRollback(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturn(true);
		$store->method('complete')->willThrowException(new \RuntimeException('Receipt failed'));
		$store->expects(self::once())->method('release');
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('rollBack');
		$db->expects(self::never())->method('commit');
		$this->expectExceptionMessage('Receipt failed');
		$this->service($store, $db)->run('owner', 'gallery.create', 'request-1', [], static fn (): array => ['id' => 7]);
	}

	public function testExternalMutationFailureRetainsItsReceipt(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturn(true);
		$store->expects(self::never())->method('release');
		$store->expects(self::once())->method('mark')->with('owner', 'gallery.publish.7', 'request-1', 'uncertain');
		$this->expectExceptionMessage('Native API failed');
		$this->service($store)->run('owner', 'gallery.publish.7', 'request-1', [], static function (): never { throw new \RuntimeException('Native API failed'); }, false);
	}

	public function testAmbiguousCommitFailureCannotReleaseTheKey(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturn(true);
		$store->expects(self::never())->method('release');
		$db = $this->createMock(IDBConnection::class);
		$db->method('commit')->willThrowException(new \RuntimeException('Connection lost at commit'));
		$this->expectExceptionMessage('Connection lost at commit');
		$this->service($store, $db)->run('owner', 'gallery.create', 'request-1', [], static fn (): array => ['id' => 7]);
	}

	public function testDifferentPayloadConflictsInsteadOfReusingTheReceipt(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturn(false);
		$store->method('find')->willReturn(['payload_hash' => 'different', 'response_json' => '{"id":7}']);
		$this->expectException(AgentRequestConflictException::class);
		$this->service($store)->run('owner', 'gallery.create', 'request-1', ['title' => 'new'], static fn () => self::fail('Conflicting key must not execute'));
	}

	public function testAnAppliedExternalResponseSurvivesCompletionFailureAndReplays(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturnOnConsecutiveCalls(true, false);
		$store->expects(self::never())->method('release');
		$stored = null;
		$store->expects(self::exactly(2))->method('mark')->willReturnCallback(static function (string $user, string $operation, string $key, string $state, ?array $response = null) use (&$stored): void {
			if ($response !== null) $stored = $response;
		});
		$store->method('complete')->willThrowException(new \RuntimeException('Completion unavailable'));
		$store->method('find')->willReturnCallback(static function () use (&$stored): array { return ['payload_hash' => hash('sha256', '[]'), 'response_json' => json_encode($stored, JSON_THROW_ON_ERROR)]; });
		$service = $this->service($store);
		try {
			$service->run('owner', 'gallery.publish.7', 'request-1', [], static fn (): array => ['id' => 7], false);
			self::fail('Completion failure should propagate');
		} catch (\RuntimeException $error) { self::assertSame('Completion unavailable', $error->getMessage()); }
		self::assertSame(['data' => ['id' => 7], 'replayed' => true], $service->run('owner', 'gallery.publish.7', 'request-1', [], static fn () => self::fail('Applied external mutation must not repeat'), false));
	}

	public function testFailureToStartTheTransactionCanReleaseAnUnexecutedRequest(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturn(true);
		$store->expects(self::once())->method('release');
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willThrowException(new \RuntimeException('Database unavailable'));
		$db->expects(self::never())->method('rollBack');
		$this->expectExceptionMessage('Database unavailable');
		$this->service($store, $db)->run('owner', 'gallery.create', 'request-1', [], static fn () => self::fail('Mutation must not start before its transaction'));
	}

	public function testStoredAppliedResponsesReconcileWithoutRepeatingTheMutation(): void {
		$store = $this->createMock(AgentRequestStore::class);
		$store->method('reserve')->willReturn(false);
		$store->method('find')->willReturn(['payload_hash' => hash('sha256', '[]'), 'execution_state' => 'uncertain', 'response_json' => '{"id":7}']);
		$store->expects(self::once())->method('complete')->with('owner', 'gallery.publish.7', 'request-1', ['id' => 7], 200);
		$result = $this->service($store)->run('owner', 'gallery.publish.7', 'request-1', [], static fn () => self::fail('Reconciliation must not execute the mutation'), false);
		self::assertSame(['data' => ['id' => 7], 'replayed' => true], $result);
	}

	private function service(AgentRequestStore $store, ?IDBConnection $db = null): AgentIdempotencyService {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(100);
		return new AgentIdempotencyService($store, $clock, $db ?? $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
	}
}
