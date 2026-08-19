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
		self::assertTrue($settings->navigation->recursive);
		self::assertSame('folder', $settings->navigation->groupBy);
		self::assertSame(3, $settings->navigation->groupDepth);
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
		self::assertSame('modern', $settings['appearance']['fontPreset']);
		self::assertSame('minimal', $settings['appearance']['openerStyle']);
		self::assertTrue($settings['appearance']['showTitle']);
		self::assertTrue($settings['appearance']['showMediaCount']);
		self::assertSame('medium', $settings['appearance']['titleSize']);
		self::assertSame('auto', $settings['publicLocale']);
		self::assertSame(9, $settings['schemaVersion']);
		self::assertSame(['sections' => [], 'showAllMedia' => true], $settings['presentation']['story']);
		self::assertSame('subtle', $settings['presentation']['motionPreset']);
		self::assertSame('auto', $settings['presentation']['lightboxFilmstripPlacement']);
		self::assertSame('autoHide', $settings['presentation']['lightboxChromeBehavior']);
		self::assertNull($settings['presentation']['instanceLogoAssetId']);
		self::assertFalse($settings['lifecycle']['enabled']);
		self::assertFalse($settings['lifecycle']['retentionHandoff']);
		self::assertSame([], $settings['metadata']['publicFields']);
		self::assertSame('auto', $settings['presentation']['theme']);
		self::assertFalse($settings['presentation']['showFilenames']);
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
		self::assertSame('cinematic', $settings['appearance']['openerStyle']);
		self::assertSame('modern', $settings['appearance']['fontPreset']);
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
		self::assertSame('#E85D4A', $settings['presentation']['accentColor']);
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

	public function testCanonicalPayloadRoundTripsWithoutChangingItsWireShape(): void {
		$canonical = GallerySettings::merge(GallerySettings::defaults(), [
			'mode' => 'collaboration',
			'publicLocale' => 'de',
			'review' => ['ratings' => true, 'pick' => true],
			'presentation' => ['layout' => 'masonry', 'theme' => 'light'],
			'delivery' => ['downloadScope' => 'selection'],
			'navigation' => ['recursive' => true, 'groupBy' => 'folder'],
			'lifecycle' => ['enabled' => true, 'revokeAfterDays' => 14],
		])->canonical();

		self::assertSame($canonical, GallerySettings::fromArray($canonical)->canonical());
		self::assertSame([
			'schemaVersion', 'mode', 'publicLocale', 'review', 'presentation',
			'delivery', 'navigation', 'security', 'metadata', 'lifecycle',
		], array_keys($canonical));
	}

	public function testLegacySchemaMarkersAreUpgradedAtTheBoundary(): void {
		$settings = GallerySettings::fromArray(['schemaVersion' => 2, 'publicLocale' => 'de']);

		self::assertSame(GallerySettings::SCHEMA_VERSION, $settings->canonical()['schemaVersion']);
		self::assertSame('de', $settings->publicLocale);
	}

	public function testRejectsUnknownPublicMetadataFields(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['metadata' => ['publicFields' => ['gps']]]);
	}

	public function testAcceptsBoundedEditorialStory(): void {
		$settings = GallerySettings::fromArray(['presentation' => [
			'layout' => 'story',
			'story' => ['showAllMedia' => false, 'sections' => [[
				'id' => 'arrival', 'title' => 'Arrival', 'body' => 'A quiet beginning.', 'style' => 'split', 'mediaIds' => [42, 43, 42],
			]]],
		]])->jsonSerialize();

		self::assertSame('story', $settings['presentation']['layout']);
		self::assertFalse($settings['presentation']['story']['showAllMedia']);
		self::assertSame([42, 43], $settings['presentation']['story']['sections'][0]['mediaIds']);
	}

	public function testRejectsDuplicateStorySectionIds(): void {
		$this->expectException(InvalidArgumentException::class);
		$section = ['id' => 'same', 'title' => '', 'body' => '', 'style' => 'full', 'mediaIds' => []];
		GallerySettings::fromArray(['presentation' => ['story' => ['sections' => [$section, $section]]]]);
	}

	public function testRejectsOversizedStorySection(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['presentation' => ['story' => ['sections' => [[
			'id' => 'too_many', 'title' => '', 'body' => '', 'style' => 'sequence', 'mediaIds' => range(1, 13),
		]]]]]);
	}
}
