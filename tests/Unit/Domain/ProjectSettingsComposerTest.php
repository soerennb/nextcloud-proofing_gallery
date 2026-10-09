<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\GalleryPurpose;
use OCA\ProofingGallery\Domain\ProjectSettingsComposer;
use OCA\ProofingGallery\Dto\GallerySettings;
use PHPUnit\Framework\TestCase;

final class ProjectSettingsComposerTest extends TestCase {
	public function testPurposeSurvivesCompleteInstanceDefaults(): void {
		foreach (GalleryPurpose::cases() as $purpose) {
			$settings = ProjectSettingsComposer::compose($purpose, GallerySettings::defaults()->canonical())->canonical();
			foreach ($purpose->settings() as $section => $value) {
				if (!is_array($value)) { self::assertSame($value, $settings[$section], $purpose->value); continue; }
				foreach ($value as $key => $expected) self::assertSame($expected, $settings[$section][$key], $purpose->value . '.' . $section . '.' . $key);
			}
		}
	}

	public function testDesignAndExplicitRequestOverridePurposeWithoutLosingWorkflow(): void {
		$settings = ProjectSettingsComposer::compose(
			GalleryPurpose::Proofing, GallerySettings::defaults()->canonical(),
			['presentation' => ['accentColor' => '#123456']],
			['publicLocale' => 'de'],
			['presentation' => ['openerStyle' => 'cinematic']],
			['review' => ['ratings' => false]],
		);
		self::assertSame('collaboration', $settings->mode->value);
		self::assertTrue($settings->review->pick);
		self::assertFalse($settings->review->ratings);
		self::assertSame('de', $settings->publicLocale);
		self::assertSame('#123456', $settings->presentation->accentColor);
		self::assertSame('cinematic', $settings->presentation->openerStyle);
	}
}
