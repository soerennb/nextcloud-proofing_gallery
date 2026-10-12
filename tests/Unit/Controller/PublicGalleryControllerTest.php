<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Controller;

use OCA\ProofingGallery\Controller\PublicGalleryController;
use OCA\ProofingGallery\Controller\ResolvedPublicShareController;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Service\DesignAssetService;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCA\ProofingGallery\Service\PublicLinkScopeService;
use OCA\ProofingGallery\Service\PublicMediaResolver;
use OCA\ProofingGallery\Service\WatermarkPreviewService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IPreview;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

final class PublicGalleryControllerTest extends TestCase {
	public function testImageStreamServesAWatermarkedDerivativeWithDownloadsDisabled(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(91);
		$file->method('getPath')->willReturn('/root/photo.jpg');
		$file->method('getMimeType')->willReturn('image/jpeg');
		$file->method('getEtag')->willReturn('original');
		$file->method('isReadable')->willReturn(true);
		$file->expects(self::never())->method('fopen');
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/root');
		$root->method('getById')->willReturn([$file]);
		$root->method('isSubNode')->willReturn(true);
		$gallery = new Gallery();
		$gallery->setSourceType('folder');
		$gallery->setOwnerUid('owner');
		$settings = GallerySettings::fromArray(['delivery' => ['downloadScope' => 'none'], 'presentation' => ['watermarkText' => 'Copyright']]);
		$context = new PublicShareContext($this->createMock(IShare::class), $gallery, $settings, new PublicLink(), PublicLinkPolicy::fromArray([]), $root);
		$collections = (new \ReflectionClass(CollectionService::class))->newInstanceWithoutConstructor();
		$media = new PublicMediaResolver($collections, new MediaTypePolicy());
		$previewFile = $this->createMock(ISimpleFile::class);
		$original = file_get_contents(__DIR__ . '/../../e2e/fixtures/kiosk.jpg');
		$previewFile->method('getContent')->willReturn($original);
		$previews = $this->createMock(IPreview::class);
		$previews->expects(self::once())->method('getPreview')->with($file, 2400, 2400, false, IPreview::MODE_FILL)->willReturn($previewFile);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(false);
		$folder->method('newFile')->willReturn($previewFile);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$assets = (new \ReflectionClass(DesignAssetService::class))->newInstanceWithoutConstructor();
		$controller = (new \ReflectionClass(PublicGalleryController::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ResolvedPublicShareController::class, 'shareContext'))->setValue($controller, $context);
		(new \ReflectionProperty($controller, 'publicMedia'))->setValue($controller, $media);
		(new \ReflectionProperty($controller, 'watermarks'))->setValue($controller, new WatermarkPreviewService($previews, $appData, $assets));
		$response = $controller->mediaStream(91);
		self::assertSame(200, $response->getStatus());
		self::assertNotSame($original, $response->getData());
		self::assertIsArray(getimagesizefromstring($response->getData()));
	}
}
