<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\GalleryPurpose;
use PHPUnit\Framework\TestCase;

final class GalleryPurposeTest extends TestCase {
	public function testDeliveryIsTheDownloadFirstPresentation(): void {
		$settings = GalleryPurpose::Delivery->settings();

		self::assertSame('presentation', $settings['mode']);
		self::assertSame('all', $settings['delivery']['downloadScope']);
		self::assertFalse($settings['review']['comments']);
	}

	public function testSelectionOnlyEnablesSelectionFeedback(): void {
		$settings = GalleryPurpose::Selection->settings();

		self::assertSame('collaboration', $settings['mode']);
		self::assertTrue($settings['review']['selections']);
		self::assertFalse($settings['review']['likes']);
		self::assertFalse($settings['review']['comments']);
	}

	public function testUploadPurposeUsesTheModeratedInbox(): void {
		$settings = GalleryPurpose::Uploads->settings();

		self::assertTrue($settings['delivery']['guestUploads']);
		self::assertSame('none', $settings['delivery']['downloadScope']);
	}
}
