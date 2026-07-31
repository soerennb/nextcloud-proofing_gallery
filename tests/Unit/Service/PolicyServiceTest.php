<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class PolicyServiceTest extends TestCase {
	public function testInvalidPersistedValuesFallBackToDefaults(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ($key) {
				'maxSelectionFiles' => '99999',
				'previewRetentionDays' => '-4',
				default => $default,
			},
		);

		$policies = new PolicyService($config);

		self::assertSame(100, $policies->get('maxSelectionFiles'));
		self::assertSame(30, $policies->get('previewRetentionDays'));
		self::assertSame(2147483648, $policies->get('maxUploadBytes'));
	}

	public function testSaveRejectsOutOfRangeValues(): void {
		$policies = new PolicyService($this->createMock(IConfig::class));

		$this->expectException(\InvalidArgumentException::class);
		$policies->save(['maxSelectionFiles' => 0]);
	}

	public function testSaveUsesAppConfig(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())
			->method('setAppValue')
			->with(Application::APP_ID, 'eventRetentionDays', '90');

		(new PolicyService($config))->save(['eventRetentionDays' => 90]);
	}
}
