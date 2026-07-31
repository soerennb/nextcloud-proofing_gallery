<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\CleanupTelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class CleanupTelemetryServiceTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];
	private IConfig $config;
	private ITimeFactory $clock;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->values[$key] ?? $default,
		);
		$this->config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				self::assertSame(Application::APP_ID, $app);
				$this->values[$key] = $value;
			},
		);
		$this->config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->values[$key]);
			},
		);
		$this->clock = $this->createMock(ITimeFactory::class);
	}

	public function testNeverRunStatus(): void {
		$this->clock->method('getTime')->willReturn(200000);

		$status = (new CleanupTelemetryService($this->config, $this->clock))->status();

		self::assertSame('never', $status['state']);
		self::assertNull($status['lastAttemptAt']);
		self::assertNull($status['lastSuccessAt']);
	}

	public function testSuccessfulAttemptIsHealthyAndClearsFailure(): void {
		$this->values['lastCleanupError'] = 'cleanup_failed:RuntimeException';
		$this->clock->method('getTime')->willReturn(200000);
		$telemetry = new CleanupTelemetryService($this->config, $this->clock);

		$telemetry->recordAttempt();
		$telemetry->recordSuccess(['events' => 1, 'uploads' => 2]);
		$status = $telemetry->status();

		self::assertSame('healthy', $status['state']);
		self::assertSame(200000, $status['lastAttemptAt']);
		self::assertSame(200000, $status['lastSuccessAt']);
		self::assertNull($status['errorCode']);
		self::assertSame(['events' => 1, 'uploads' => 2], json_decode($status['lastResult'], true));
	}

	public function testOldSuccessIsStale(): void {
		$this->values = [
			'lastCleanupAttemptAt' => '100000',
			'lastCleanupSuccessAt' => '100000',
		];
		$this->clock->method('getTime')->willReturn(100000 + CleanupTelemetryService::STALE_AFTER_SECONDS + 1);

		$status = (new CleanupTelemetryService($this->config, $this->clock))->status();

		self::assertSame('stale', $status['state']);
	}

	public function testBetaThreeSuccessTimestampRemainsVisibleAfterUpgrade(): void {
		$this->values = ['lastCleanupAt' => '190000'];
		$this->clock->method('getTime')->willReturn(200000);

		$status = (new CleanupTelemetryService($this->config, $this->clock))->status();

		self::assertSame('healthy', $status['state']);
		self::assertSame(190000, $status['lastAttemptAt']);
		self::assertSame(190000, $status['lastSuccessAt']);
	}

	public function testFailureStoresOnlyExceptionType(): void {
		$this->clock->method('getTime')->willReturn(300000);
		$telemetry = new CleanupTelemetryService($this->config, $this->clock);

		$telemetry->recordAttempt();
		$telemetry->recordFailure(new \RuntimeException('/private/path must not be stored'));
		$status = $telemetry->status();

		self::assertSame('failed', $status['state']);
		self::assertSame('cleanup_failed:RuntimeException', $status['errorCode']);
		self::assertStringNotContainsString('/private/path', json_encode($this->values, JSON_THROW_ON_ERROR));
	}
}
