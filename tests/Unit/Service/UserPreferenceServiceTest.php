<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\UserPreferenceService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class UserPreferenceServiceTest extends TestCase {
	public function testEmailSubscriptionsAreOptInByDefault(): void {
		$preferences = (new UserPreferenceService($this->createMock(IConfig::class)))->get('user');

		self::assertFalse($preferences['notifications']['email']['enabled']);
		self::assertTrue($preferences['notifications']['nextcloud']['enabled']);
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
			'notifications' => [
				'nextcloud' => ['enabled' => true, 'events' => ['selection.created', 'unknown']],
				'email' => ['enabled' => false, 'events' => ['selection.created', 'unknown'], 'frequency' => 'daily'],
			],
		]);

		self::assertSame('selection', $saved['defaultPurpose']);
		self::assertSame(['selection.created'], $saved['notifications']['nextcloud']['events']);
		self::assertSame(['selection.created'], $saved['notifications']['email']['events']);
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

	public function testUnknownNotificationChannelPreferenceIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new UserPreferenceService($this->createMock(IConfig::class)))->save('user', [
			'notifications' => ['nextcloud' => ['enabled' => true, 'sound' => true]],
		]);
	}
}
