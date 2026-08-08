<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\ProjectionBackfillState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ProjectionBackfillStateTest extends TestCase {
	public function testLegacyLifecycleCompletionRemainsCompatible(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())->method('getAppValue')
			->with(Application::APP_ID, 'lifecycleProjectionV1Complete', 'pending')->willReturn('1');
		$time = $this->createMock(ITimeFactory::class);

		$state = new ProjectionBackfillState($config, $time);

		self::assertTrue($state->isComplete(ProjectionBackfillState::LIFECYCLE));
	}

	public function testRunningStateCanBeResetToPendingWithoutLosingCursor(): void {
		$values = ['galleryListProjectionV1State' => 'running', 'galleryListProjectionV1Cursor' => '42'];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $values[$key] ?? $default,
		);
		$config->expects(self::exactly(2))->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$values): void { $values[$key] = $value; },
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1234);
		$state = new ProjectionBackfillState($config, $time);

		$state->markPending(ProjectionBackfillState::GALLERY_LIST);

		self::assertSame('pending', $values['galleryListProjectionV1State']);
		self::assertSame('1234', $values['galleryListProjectionV1UpdatedAt']);
		self::assertSame(42, $state->cursor(ProjectionBackfillState::GALLERY_LIST));
	}
}
