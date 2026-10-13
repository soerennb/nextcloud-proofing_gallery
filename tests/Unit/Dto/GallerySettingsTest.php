<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Dto;

use InvalidArgumentException;
use OCA\ProofingGallery\Dto\GallerySettings;
use PHPUnit\Framework\TestCase;

final class GallerySettingsTest extends TestCase {
	public function testMalformedSelectionDateIsAValidationFailure(): void {
		$this->expectException(\InvalidArgumentException::class);
		GallerySettings::fromArray(['review' => ['selectionDueDate' => 'not-a-date']]);
	}

	public function testRecursiveNavigationRejectsUnboundedDepth(): void {
		$this->expectException(\InvalidArgumentException::class);
		GallerySettings::fromArray(['navigation' => ['groupDepth' => 9]]);
	}

	public function testDefaultsAreSafe(): void {
		$settings = GallerySettings::defaults()->jsonSerialize();

		self::assertSame('presentation', $settings['mode']);
		self::assertSame('none', $settings['delivery']['downloadScope']);
		self::assertFalse($settings['delivery']['guestUploads']);
		self::assertFalse($settings['lifecycle']['enabled']);
		self::assertFalse($settings['lifecycle']['retentionHandoff']);
		self::assertSame([], $settings['metadata']['publicFields']);
		self::assertFalse($settings['presentation']['showFilenames']);
	}

	public function testRejectsMalformedDesignAssetId(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['presentation' => ['logoAssetId' => '../foreign']]);
	}

	public function testRejectsOutOfRangeWatermarkImageScale(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['presentation' => ['watermarkImageScale' => 51]]);
	}

	public function testRejectsUnknownKeys(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['isAdmin' => true]);
	}

	public function testRejectsUnknownAppearanceSettings(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['appearance' => ['trackingPixel' => 'https://example.test']]);
	}

	public function testAcceptsLegacyAliasesButEmitsCanonicalSections(): void {
		$settings = GallerySettings::fromArray([
			'mode' => 'collaboration',
			'presentation' => ['theme' => 'light', 'layout' => 'masonry', 'tileGap' => 'tight'],
			'review' => ['comments' => false, 'selectionWarningThreshold' => 12, 'selectionMinimum' => 4, 'selectionMaximum' => 20, 'selectionDueDate' => '2026-11-30'],
			'delivery' => ['downloadScope' => 'selection'],
			'navigation' => ['sortBy' => 'modified', 'sortDirection' => 'desc'],
		])->jsonSerialize();

		self::assertSame('light', $settings['presentation']['theme']);
		self::assertSame('masonry', $settings['presentation']['layout']);
		self::assertFalse($settings['review']['comments']);
		self::assertSame(4, $settings['review']['selectionMinimum']);
		self::assertSame(20, $settings['review']['selectionMaximum']);
		self::assertSame('2026-11-30', $settings['review']['selectionDueDate']);
		self::assertSame('selection', $settings['delivery']['downloadScope']);
		self::assertArrayNotHasKey('allowDownloads', $settings);
		self::assertArrayNotHasKey('appearance', $settings);
	}

	public function testPublicPolicyTreatsLegacyAnnotationsWithoutCommentsAsPresentation(): void {
		$policy = \OCA\ProofingGallery\Domain\PublicLinkPolicy::fromArray([
			'comments' => false,
			'annotations' => true,
		]);
		$settings = GallerySettings::fromArray([
			'mode' => 'collaboration',
			'review' => ['comments' => true, 'annotations' => true],
		])->withPublicPolicy($policy)->jsonSerialize();

		self::assertSame('presentation', $settings['mode']);
		self::assertFalse($settings['review']['comments']);
		self::assertFalse($settings['review']['annotations']);
	}

	public function testPartialUpdatesPreserveUneditedPermissions(): void {
		$original = GallerySettings::merge(GallerySettings::defaults(), [
			'mode' => 'collaboration',
			'review' => ['comments' => true],
			'delivery' => ['downloadScope' => 'selection'],
		]);
		$settings = GallerySettings::merge($original, ['review' => ['likes' => false]])->canonical();

		self::assertFalse($settings['review']['likes']);
		self::assertTrue($settings['review']['comments']);
		self::assertSame('selection', $settings['delivery']['downloadScope']);
		self::assertFalse($settings['delivery']['guestUploads']);
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

	public function testRejectsOversizedStorySection(): void {
		$this->expectException(InvalidArgumentException::class);
		GallerySettings::fromArray(['presentation' => ['story' => ['sections' => [[
			'id' => 'too_many', 'title' => '', 'body' => '', 'style' => 'sequence', 'mediaIds' => range(1, 13),
		]]]]]);
	}
}
