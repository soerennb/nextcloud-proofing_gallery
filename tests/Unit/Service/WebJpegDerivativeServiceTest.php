<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\WatermarkPreviewService;
use OCA\ProofingGallery\Service\WebJpegDerivativeService;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\IPreview;
use PHPUnit\Framework\TestCase;

final class WebJpegDerivativeServiceTest extends TestCase {
	private function service(): WebJpegDerivativeService {
		return new WebJpegDerivativeService(
			$this->createMock(IPreview::class),
			$this->createMock(IAppData::class),
			(new \ReflectionClass(WatermarkPreviewService::class))->newInstanceWithoutConstructor(),
		);
	}

	public function testPublishesOnlyTheTwoBoundedWebPresets(): void {
		self::assertSame(['web-2048', 'web-1600'], $this->service()->presets());
	}

	public function testCreatesSafeJpegFilename(): void {
		self::assertSame('Sommer-Fest.jpg', $this->service()->filename('Sommer Fest.NEF'));
	}

	public function testRejectsUnknownPresetBeforeReadingSource(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->derivative(
			$this->createMock(File::class),
			'original',
			false,
			\OCA\ProofingGallery\Dto\GallerySettings::defaults()->presentation,
			'owner',
		);
	}
}
