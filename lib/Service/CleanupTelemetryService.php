<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Throwable;

final class CleanupTelemetryService {
	public const STALE_AFTER_SECONDS = 36 * 3600;

	public function __construct(
		private IConfig $config,
		private ITimeFactory $clock,
	) {
	}

	public function recordAttempt(): void {
		$this->config->setAppValue(Application::APP_ID, 'lastCleanupAttemptAt', (string)$this->clock->getTime());
	}

	/** @param array<string, int> $result */
	public function recordSuccess(array $result): void {
		$now = (string)$this->clock->getTime();
		$this->config->setAppValue(Application::APP_ID, 'lastCleanupSuccessAt', $now);
		$this->config->setAppValue(Application::APP_ID, 'lastCleanupResult', json_encode($result, JSON_THROW_ON_ERROR));
		$this->config->deleteAppValue(Application::APP_ID, 'lastCleanupError');
	}

	public function recordFailure(Throwable $exception): void {
		$parts = explode('\\', $exception::class);
		$code = 'cleanup_failed:' . end($parts);
		$this->config->setAppValue(Application::APP_ID, 'lastCleanupError', $code);
	}

	/**
	 * @return array{
	 *   state: 'never'|'healthy'|'stale'|'failed',
	 *   lastAttemptAt: ?int,
	 *   lastSuccessAt: ?int,
	 *   lastResult: string,
	 *   errorCode: ?string
	 * }
	 */
	public function status(): array {
		$legacySuccess = $this->integerValue('lastCleanupAt');
		$attempt = $this->integerValue('lastCleanupAttemptAt') ?? $legacySuccess;
		$success = $this->integerValue('lastCleanupSuccessAt') ?? $legacySuccess;
		$error = $this->config->getAppValue(Application::APP_ID, 'lastCleanupError', '');

		if ($attempt === null) {
			$state = 'never';
		} elseif ($error !== '' && ($success === null || $attempt > $success)) {
			$state = 'failed';
		} elseif ($success === null || $this->clock->getTime() - $success > self::STALE_AFTER_SECONDS) {
			$state = 'stale';
		} else {
			$state = 'healthy';
		}

		return [
			'state' => $state,
			'lastAttemptAt' => $attempt,
			'lastSuccessAt' => $success,
			'lastResult' => $this->config->getAppValue(Application::APP_ID, 'lastCleanupResult', ''),
			'errorCode' => $error === '' ? null : $error,
		];
	}

	private function integerValue(string $key): ?int {
		$value = $this->config->getAppValue(Application::APP_ID, $key, '');
		return $value === '' ? null : (int)$value;
	}
}
