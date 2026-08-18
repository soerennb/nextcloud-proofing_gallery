<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Exception\UploadBusyException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

final class UploadLockService {
	private const WAIT_TIMEOUT_MICROSECONDS = 5_000_000;
	private const INITIAL_DELAY_MICROSECONDS = 25_000;
	private const MAX_DELAY_MICROSECONDS = 250_000;

	public function __construct(private ILockingProvider $locks) {
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function immediately(string $path, string $readablePath, callable $callback): mixed {
		return $this->withLock($path, $readablePath, $callback, false);
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function wait(string $path, string $readablePath, callable $callback): mixed {
		return $this->withLock($path, $readablePath, $callback, true);
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	private function withLock(string $path, string $readablePath, callable $callback, bool $wait): mixed {
		$deadline = hrtime(true) + self::WAIT_TIMEOUT_MICROSECONDS * 1000;
		$delay = self::INITIAL_DELAY_MICROSECONDS;
		while (true) {
			try {
				$this->locks->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE, $readablePath);
				break;
			} catch (LockedException $exception) {
				if (!$wait || hrtime(true) >= $deadline) {
					throw new UploadBusyException('Upload destination is busy', previous: $exception);
				}
				usleep($delay);
				$delay = min(self::MAX_DELAY_MICROSECONDS, $delay * 2);
			}
		}

		try {
			return $callback();
		} finally {
			$this->locks->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
