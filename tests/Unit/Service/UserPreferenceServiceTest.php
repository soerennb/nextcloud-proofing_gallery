<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\UserPreferenceService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class UserPreferenceServiceTest extends TestCase {
	public function testEmailSubscriptionsAreOptInByDefault(): void {
		$preferences = (new UserPreferenceService($this->createMock(IConfig::class)))->get('user');

		self::assertFalse($preferences['notifications']['email']);
	}

	public function testPreferencesPersistAcrossDevicesAndFilterUnknownEvents(): void {
		$stored = '';
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $user, string $app, string $key, string $default) use (&$stored): string {
				return $stored === '' ? $default : $stored;
			},
		);
		$config->method('setUserValue')->willReturnCallback(
			static function (string $user, string $app, string $key, string $value) use (&$stored): void { $stored = $value; },
		);
		$service = new UserPreferenceService($config);

		$saved = $service->save('photographer', [
			'defaultPurpose' => 'selection',
			'parentFolder' => ['id' => 42, 'name' => 'Clients'],
			'notifications' => ['email' => false, 'events' => ['selection.created', 'unknown']],
		]);

		self::assertSame('selection', $saved['defaultPurpose']);
		self::assertSame(['selection.created'], $saved['notifications']['events']);
		self::assertSame($saved, $service->get('photographer'));
	}

	public function testInvalidPurposeIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new UserPreferenceService($this->createMock(IConfig::class)))->save('user', ['defaultPurpose' => 'unknown']);
	}

	public function testUnknownPreferenceIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new UserPreferenceService($this->createMock(IConfig::class)))->save('user', ['trackingPixel' => true]);
	}
}
