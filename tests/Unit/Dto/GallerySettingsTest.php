<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Dto;

use InvalidArgumentException;
use OCA\ProofingGallery\Dto\GallerySettings;
use PHPUnit\Framework\TestCase;

final class GallerySettingsTest extends TestCase {
	public function testRecursiveNavigationDefaultsAreBounded(): void {
		$settings = GallerySettings::merge(GallerySettings::defaults(), [
			'navigation' => ['recursive' => true, 'groupBy' => 'folder', 'groupDepth' => 3],
		]);
		self::assertTrue($settings->navigation['recursive']);
		self::assertSame('folder', $settings->navigation['groupBy']);
		self::assertSame(3, $settings->navigation['groupDepth']);
	}

	public function testRecursiveNavigationRejectsUnboundedDepth(): void {
		$this->expectException(\InvalidArgumentException::class);
		GallerySettings::fromArray(['navigation' => ['groupDepth' => 9]]);
	}

	public function testDefaultsAreSafe(): void {
		$settings = GallerySettings::defaults()->jsonSerialize();

		self::assertSame('presentation', $settings['mode']);
		self::assertFalse($settings['allowDownloads']);
		self::assertFalse($settings['allowGuestUploads']);
		self::assertCount(4, $settings['colorLabels']);
		self::assertSame(50, $settings['appearance']['heroFocusX']);
		self::assertSame('system', $settings['appearance']['fontPreset']);
		self::assertSame('compact', $settings['appearance']['openerStyle']);
		self::assertSame('auto', $settings['publicLocale']);
		self::assertSame(5, $settings['schemaVersion']);
		self::assertNull($settings['presentation']['instanceLogoAssetId']);
		self::assertFalse($settings['lifecycle']['enabled']);
		self::assertSame([], $settings['metadata']['publicFields']);
		self::assertSame('dark', $settings['presentation']['theme']);
		self::assertSame('none', $settings['delivery']['downloadScope']);
		self::assertTrue($settings['review']['likes']);
	}

	public function testRejectsUnknownKeys(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['isAdmin' => true]);
	}

	public function testRejectsInvalidColorLabels(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['colorLabels' => ['One']]);
	}

	public function testRejectsUnknownAppearanceSettings(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['appearance' => ['trackingPixel' => 'https://example.test']]);
	}

	public function testRejectsOutOfBoundsWatermarkAndFocus(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['appearance' => [
			'heroFocusX' => 101,
			'watermarkOpacity' => 99,
		]]);
	}

	public function testNormalizesLegacyAppearanceDefaults(): void {
		$settings = GallerySettings::fromArray([
			'mode' => 'collaboration',
			'appearance' => ['accentColor' => '#abcdef'],
		])->jsonSerialize();

		self::assertSame('collaboration', $settings['mode']);
		self::assertSame('#abcdef', $settings['appearance']['accentColor']);
		self::assertSame(50, $settings['appearance']['heroFocusY']);
		self::assertSame('compact', $settings['appearance']['openerStyle']);
	}

	public function testPreservesCinematicLegacyHeroIntent(): void {
		$settings = GallerySettings::fromArray([
			'appearance' => ['heroFileId' => 42],
		])->jsonSerialize();

		self::assertSame('cinematic', $settings['appearance']['openerStyle']);
	}

	public function testRejectsUnknownOpenerStyle(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['appearance' => ['openerStyle' => 'fullscreen']]);
	}

	public function testRejectsUnknownPublicLocale(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['publicLocale' => 'fr']);
	}

	public function testAcceptsVersionTwoSectionsAndKeepsLegacyAliases(): void {
		$settings = GallerySettings::fromArray([
			'mode' => 'collaboration',
			'presentation' => ['theme' => 'light', 'layout' => 'masonry', 'tileGap' => 'tight'],
			'review' => ['comments' => false, 'selectionWarningThreshold' => 12],
			'delivery' => ['downloadScope' => 'selection'],
			'navigation' => ['sortBy' => 'modified', 'sortDirection' => 'desc'],
		])->jsonSerialize();

		self::assertSame('light', $settings['presentation']['theme']);
		self::assertSame('masonry', $settings['presentation']['layout']);
		self::assertFalse($settings['review']['comments']);
		self::assertSame('selection', $settings['delivery']['downloadScope']);
		self::assertTrue($settings['allowDownloads']);
		self::assertSame($settings['presentation'], $settings['appearance']);
	}

	public function testDeepMergesOneSettingsSection(): void {
		$settings = GallerySettings::merge(GallerySettings::defaults(), [
			'presentation' => ['theme' => 'light'],
		])->jsonSerialize();

		self::assertSame('light', $settings['presentation']['theme']);
		self::assertSame('#1f6f8b', $settings['presentation']['accentColor']);
	}

	public function testAcceptsValidInstanceLogoAsset(): void {
		$settings = GallerySettings::fromArray([
			'presentation' => ['instanceLogoAssetId' => str_repeat('a', 32) . '.svg'],
		])->jsonSerialize();

		self::assertSame(str_repeat('a', 32) . '.svg', $settings['presentation']['instanceLogoAssetId']);
	}

	public function testRejectsUnsafeInstanceLogoAssetPath(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['presentation' => ['instanceLogoAssetId' => '../logo.svg']]);
	}

	public function testAcceptsOnlyPrivacySafePublicMetadataFields(): void {
		$settings = GallerySettings::fromArray([
			'metadata' => ['publicFields' => ['camera', 'lens', 'exposure']],
		])->jsonSerialize();

		self::assertSame(['camera', 'lens', 'exposure'], $settings['metadata']['publicFields']);
	}

	public function testRejectsUnknownPublicMetadataFields(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['metadata' => ['publicFields' => ['gps']]]);
	}
}
