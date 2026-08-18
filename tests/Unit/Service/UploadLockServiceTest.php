<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Exception\UploadBusyException;
use OCA\ProofingGallery\Service\UploadLockService;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

final class UploadLockServiceTest extends TestCase {
	public function testRunsCallbackAndAlwaysReleasesLock(): void {
		$provider = $this->createMock(ILockingProvider::class);
		$provider->expects(self::once())->method('acquireLock')->with('upload/one', ILockingProvider::LOCK_EXCLUSIVE, 'Upload one');
		$provider->expects(self::once())->method('releaseLock')->with('upload/one', ILockingProvider::LOCK_EXCLUSIVE);
		$service = new UploadLockService($provider);

		self::assertSame('completed', $service->immediately('upload/one', 'Upload one', static fn (): string => 'completed'));
	}

	public function testReleasesLockWhenCallbackFails(): void {
		$provider = $this->createMock(ILockingProvider::class);
		$provider->expects(self::once())->method('releaseLock')->with('upload/two', ILockingProvider::LOCK_EXCLUSIVE);
		$service = new UploadLockService($provider);

		$this->expectException(\RuntimeException::class);
		$service->immediately('upload/two', 'Upload two', static function (): never {
			throw new \RuntimeException('failed');
		});
	}

	public function testConvertsImmediateContentionToUploadBusy(): void {
		$provider = $this->createMock(ILockingProvider::class);
		$provider->method('acquireLock')->willThrowException(new LockedException('upload/three'));
		$provider->expects(self::never())->method('releaseLock');
		$service = new UploadLockService($provider);

		$this->expectException(UploadBusyException::class);
		$service->immediately('upload/three', 'Upload three', static fn () => null);
	}
}
