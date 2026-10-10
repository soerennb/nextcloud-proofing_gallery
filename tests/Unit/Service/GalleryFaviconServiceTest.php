<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\GalleryFaviconService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class GalleryFaviconServiceTest extends TestCase {
	public function testBothVectorsUseTheCurrentGallerySilhouette(): void {
		$directory = dirname(__DIR__, 3) . '/img/';
		preg_match('/\bd="([^"]+)"/', file_get_contents($directory . 'app.svg'), $source);
		foreach (['favicon.svg', 'favicon-mask.svg'] as $file) {
			preg_match('/\bd="([^"]+)"/', file_get_contents($directory . $file), $favicon);
			self::assertSame($source[1], $favicon[1]);
		}
	}

	public function testVersionsEveryDirectAssetByItsOwnContents(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects(self::never())->method('imagePath');
		$urls->expects(self::exactly(4))->method('linkTo')->willReturnCallback(static function (string $app, string $file, array $arguments): string {
			self::assertSame('proofing_gallery', $app);
			self::assertSame(substr(hash_file('sha256', dirname(__DIR__, 3) . '/' . $file), 0, 16), $arguments['v']);
			return '/nextcloud/custom_apps/' . $app . '/' . $file . '?v=' . $arguments['v'];
		});
		$links = (new GalleryFaviconService($urls))->links();
		self::assertSame(['icon', 'icon', 'apple-touch-icon', 'mask-icon'], array_column($links, 'rel'));
		self::assertSame('image/svg+xml', $links[1]['type']);
		self::assertSame('180x180', $links[2]['sizes']);
		self::assertSame('#00679e', $links[3]['color']);
	}

	public function testRasterAssetsContainTheRequiredFallbackSizes(): void {
		$directory = dirname(__DIR__, 3) . '/img/';
		$ico = file_get_contents($directory . 'favicon.ico');
		self::assertSame(3, unpack('vcount', substr($ico, 4, 2))['count']);
		foreach ([16, 32, 48] as $index => $size) {
			self::assertSame($size, ord($ico[6 + $index * 16]));
			self::assertSame($size, ord($ico[7 + $index * 16]));
		}
		$touch = getimagesize($directory . 'favicon-touch.png');
		self::assertSame([180, 180], array_slice($touch, 0, 2));
		self::assertSame('image/png', $touch['mime']);
	}
}
