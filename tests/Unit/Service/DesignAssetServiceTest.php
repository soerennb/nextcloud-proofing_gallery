<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\DesignAssetService;
use PHPUnit\Framework\TestCase;

final class DesignAssetServiceTest extends TestCase {
	public function testAcceptsSelfContainedSvgAndRejectsReferences(): void {
		$service = (new \ReflectionClass(DesignAssetService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(DesignAssetService::class, 'safeSvg');

		self::assertTrue($method->invoke($service, '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z"/></svg>'));
		self::assertFalse($method->invoke($service, '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><path d="M0 0h10v10z"/></a></svg>'));
		self::assertFalse($method->invoke($service, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
	}

	public function testRasterWatermarkIsDecodedAndNormalizedToPng(): void {
		$source = imagecreatetruecolor(12, 7);
		self::assertInstanceOf(\GdImage::class, $source);
		imagefill($source, 0, 0, imagecolorallocate($source, 220, 10, 20));
		ob_start();
		imagejpeg($source, null, 90);
		$content = (string)ob_get_clean();
		imagedestroy($source);

		$service = (new \ReflectionClass(DesignAssetService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(DesignAssetService::class, 'normalize');
		$result = $method->invoke($service, $content, 'watermark');

		self::assertIsArray($result);
		self::assertSame('image/png', $result[1]);
		self::assertSame('png', $result[2]);
		self::assertSame(12, $result[3]);
		self::assertSame(7, $result[4]);
		self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $result[0]);
	}
}
