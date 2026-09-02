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
		self::assertSame(1000, $policies->get('maxGalleryDownloadFiles'));
		self::assertSame(30, $policies->get('previewRetentionDays'));
		self::assertSame(2147483648, $policies->get('maxUploadBytes'));
		self::assertSame(67108864, $policies->get('metadataMaxBytes'));
		self::assertSame(100, $policies->get('metadataBatchSize'));
		self::assertSame(1, $policies->get('xmpWritingEnabled'));
		self::assertSame(25000, $policies->get('maxIndexedMedia'));
		self::assertSame(500, $policies->get('maxPublicLinks'));
		self::assertSame(90, $policies->get('shareAuditRetentionDays'));
		self::assertSame(30, $policies->get('notificationQueueRetentionDays'));
		self::assertSame(30, $policies->get('deadLetterRetentionDays'));
		self::assertSame(10737418240, $policies->get('maxVideoInputBytes'));
		self::assertSame(1080, $policies->get('videoMaxHeight'));
		self::assertSame(10000, $policies->get('maxSemanticMedia'));
		self::assertSame(3, $policies->get('maxLivePushCredentials'));
		self::assertSame(3, $policies->get('maxCustomDomainsPerGallery'));
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

	public function testGalleryDefaultsExposeOneCanonicalSourceOfTruth(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $key === 'galleryDefaults'
				? json_encode(['showFilenames' => false], JSON_THROW_ON_ERROR)
				: $default,
		);

		$defaults = (new PolicyService($config))->galleryDefaults();

		self::assertFalse($defaults['presentation']['showFilenames']);
		self::assertArrayNotHasKey('showFilenames', $defaults);
		self::assertArrayNotHasKey('appearance', $defaults);
	}

	public function testInstanceSettingsAreValidatedAndMergedWithSafeDefaults(): void {
		$stored = '';
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default) use (&$stored): string {
				return $key === 'instanceSettingsV2' && $stored !== '' ? $stored : $default;
			},
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				if ($key === 'instanceSettingsV2') $stored = $value;
			},
		);

		$policies = new PolicyService($config);
		$saved = $policies->saveInstanceSettings([
			'access' => ['creatorGroups' => [' photographers ', 'photographers']],
			'features' => ['downloads' => false],
			'workflow' => ['defaultPurpose' => 'proofing'],
			'branding' => ['logoAssetId' => str_repeat('A', 32) . '.png'],
			'media' => ['ffmpegPath' => '/usr/local/bin/ffmpeg', 'transcodeConcurrency' => 2, 'transcodePreset' => 'veryfast'],
		]);

		self::assertSame(['photographers'], $saved['access']['creatorGroups']);
		self::assertFalse($saved['features']['downloads']);
		self::assertTrue($saved['features']['comments']);
		self::assertSame('proofing', $saved['workflow']['defaultPurpose']);
		self::assertSame(str_repeat('A', 32) . '.png', $saved['branding']['logoAssetId']);
		self::assertSame('/usr/local/bin/ffmpeg', $saved['media']['ffmpegPath']);
		self::assertSame(2, $saved['media']['transcodeConcurrency']);
		self::assertSame('disabled', $saved['semantic']['provider']);
		self::assertFalse($saved['livePush']['enabled']);
		self::assertFalse($saved['customDomains']['enabled']);
		self::assertSame(['enabled' => false, 'systemTagId' => ''], $saved['retention']);
	}

	public function testExternalSemanticProviderRequiresExplicitHttpsTransfer(): void {
		$policies = new PolicyService($this->createMock(IConfig::class));
		$this->expectException(\InvalidArgumentException::class);
		$policies->saveInstanceSettings(['semantic' => [
			'provider' => 'https', 'endpoint' => 'https://vision.example/embed', 'externalTransfer' => false,
		]]);
	}

	public function testLivePushCanEnableTheHttpsIngressWithoutGatewaySettings(): void {
		$policies = new PolicyService($this->createMock(IConfig::class));
		$saved = $policies->saveInstanceSettings(['livePush' => ['enabled' => true]]);
		self::assertSame(['enabled' => true], $saved['livePush']);
		self::assertSame([
			'enabled' => false,
			'endpointPath' => '/apps/proofing_gallery/live-push/upload',
			'protocol' => 'https-put',
		], $policies->livePushSettings());
	}

	public function testInstanceSettingsRejectUnknownFeature(): void {
		$policies = new PolicyService($this->createMock(IConfig::class));
		$this->expectException(\InvalidArgumentException::class);
		$policies->saveInstanceSettings(['features' => ['telepathy' => true]]);
	}

	public function testInstanceSettingsRejectUnsafeLogoAssetId(): void {
		$policies = new PolicyService($this->createMock(IConfig::class));
		$this->expectException(\InvalidArgumentException::class);
		$policies->saveInstanceSettings(['branding' => ['logoAssetId' => 'https://tracker.invalid/logo.svg']]);
	}

	public function testInstanceSettingsRejectUnknownSectionKey(): void {
		$policies = new PolicyService($this->createMock(IConfig::class));
		$this->expectException(\InvalidArgumentException::class);
		$policies->saveInstanceSettings(['branding' => ['trackingPixel' => 'https://invalid.test']]);
	}

	public function testRetentionRequiresAnExplicitSystemTag(): void {
		$policies = new PolicyService($this->createMock(IConfig::class));
		$this->expectException(\InvalidArgumentException::class);
		$policies->saveInstanceSettings(['retention' => ['enabled' => true]]);
	}
}
