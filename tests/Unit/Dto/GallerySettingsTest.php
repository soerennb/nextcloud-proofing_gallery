<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Dto;

use InvalidArgumentException;
use OCA\ProofingGallery\Dto\GallerySettings;
use PHPUnit\Framework\TestCase;

final class GallerySettingsTest extends TestCase {
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
}
