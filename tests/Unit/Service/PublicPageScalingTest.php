<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Controller\PublicGalleryController;
use OCA\ProofingGallery\Service\EmbeddedMetadataExtractor;
use OCA\ProofingGallery\Service\FolderPublicPageReader;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Regression guards for the public page scaling work: single summary reads, search filtering and bounded preview sizes. */
final class PublicPageScalingTest extends TestCase {
	public function testPublicItemReadsTheFileSummaryOnceForMetadataAndGeometry(): void {
		$details = $this->createMock(IFilesMetadata::class);
		$details->method('hasKey')->willReturn(true);
		$details->method('getEtag')->willReturn('etag-1');
		$details->method('getArray')->willReturn([
			'capturedAt' => 1700000000, 'camera' => 'Test Camera', 'width' => 1600, 'height' => 1200, 'iso' => 100,
		]);
		$metadata = $this->createMock(IFilesMetadataManager::class);
		$metadata->expects(self::once())->method('getMetadata')->with(7, false)->willReturn($details);

		$parent = $this->createMock(Folder::class);
		$parent->method('nodeExists')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('getEtag')->willReturn('etag-1');
		$file->method('getName')->willReturn('photo.jpg');
		$file->method('getParent')->willReturn($parent);

		$service = new MediaMetadataService(
			$metadata,
			(new ReflectionClass(PolicyService::class))->newInstanceWithoutConstructor(),
			(new ReflectionClass(EmbeddedMetadataExtractor::class))->newInstanceWithoutConstructor(),
			$this->createMock(IEventDispatcher::class),
		);

		$item = $service->publicItem($file, ['capturedAt', 'camera']);

		self::assertSame(['state' => 'ready', 'capturedAt' => 1700000000, 'camera' => 'Test Camera'], $item['metadata']);
		self::assertSame(['width' => 1600, 'height' => 1200], $item['geometry']);
	}

	public function testPreviewSizesSnapToBoundedBuckets(): void {
		$bucket = new ReflectionMethod(PublicGalleryController::class, 'previewBucket');

		self::assertSame(140, $bucket->invoke(null, 1));
		self::assertSame(160, $bucket->invoke(null, 160));
		self::assertSame(180, $bucket->invoke(null, 161));
		self::assertSame(256, $bucket->invoke(null, 181));
		// Sizes the public client requests keep their exact size and aspect ratio.
		foreach ([140, 180, 520, 720, 900, 1400, 1800, 2200, 2400] as $clientSize) {
			self::assertSame($clientSize, $bucket->invoke(null, $clientSize));
		}
		self::assertSame(900, $bucket->invoke(null, 900));
		self::assertSame(1200, $bucket->invoke(null, 901));
		self::assertSame(2400, $bucket->invoke(null, 2400));
		self::assertSame(2400, $bucket->invoke(null, 9999));

		$distinct = [];
		for ($value = 1; $value <= 2400; $value++) {
			$distinct[(int)$bucket->invoke(null, $value)] = true;
		}
		self::assertLessThanOrEqual(13, count($distinct), 'arbitrary sizes must map onto a fixed bucket list');
	}

	public function testSearchFiltersOrderedEntriesCaseInsensitively(): void {
		$matching = new ReflectionMethod(FolderPublicPageReader::class, 'matching');
		$entries = [
			['id' => 1, 'name' => 'Brautpaar-01.jpg', 'folder' => false, 'group' => 'image'],
			['id' => 2, 'name' => 'Empfang.jpg', 'folder' => false, 'group' => 'image'],
			['id' => 3, 'name' => 'brautjungfern.png', 'folder' => false, 'group' => 'image'],
		];

		self::assertSame($entries, $matching->invoke(null, $entries, ''));
		self::assertSame([$entries[0], $entries[2]], $matching->invoke(null, $entries, 'BRAUT'));
		self::assertSame([], $matching->invoke(null, $entries, 'nomatch'));
	}
}
