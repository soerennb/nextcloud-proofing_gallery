<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class MediaMetadataServiceTest extends TestCase {
	public function testEditableFieldsRoundTripThroughAdobeCompatibleXmp(): void {
		$service = $this->service();
		$document = $this->invoke($service, 'newXmpDocument');
		$this->invoke($service, 'applyEditableFields', $document, [
			'title' => 'Final portrait',
			'description' => 'Client-approved crop',
			'creator' => 'Studio Example',
			'copyright' => '© Studio Example',
			'keywords' => ['Portrait', 'Approved'],
			'rating' => 5,
			'label' => 'Green',
		]);

		$xml = $document->saveXML();
		self::assertIsString($xml);
		$loaded = $this->invoke($service, 'loadXmp', $xml);
		self::assertSame([
			'title' => 'Final portrait',
			'description' => 'Client-approved crop',
			'creator' => 'Studio Example',
			'copyright' => '© Studio Example',
			'keywords' => ['Portrait', 'Approved'],
			'rating' => 5,
			'label' => 'Green',
		], $this->invoke($service, 'readXmpValues', $loaded));
	}

	public function testProofingExportUsesStandardAndAppNamespacesWithoutDroppingUnknownData(): void {
		$service = $this->service();
		$fixture = file_get_contents(__DIR__ . '/../../Fixtures/xmp/adobe-existing.xmp');
		self::assertIsString($fixture);
		$document = $this->invoke($service, 'loadXmp', $fixture);

		$this->invoke($service, 'applyProofingFields', $document, 7, 'München & Co', 'selection-1', 'Finals', 3, 'Green');
		$this->invoke($service, 'applyProofingFields', $document, 7, 'München & Co', 'selection-2', 'Approved', 4, 'Blue');
		$xml = $document->saveXML();

		self::assertIsString($xml);
		self::assertStringContainsString('xmp:Rating="5"', $xml);
		self::assertStringContainsString('xmp:Label="Blue"', $xml);
		self::assertStringContainsString('Proofing|München &amp; Co|Approved', $xml);
		self::assertStringContainsString('pg:GalleryId="7"', $xml);
		self::assertStringContainsString('pg:SelectionId="selection-2"', $xml);
		self::assertStringContainsString('pg:LikeCount="4"', $xml);
		self::assertStringNotContainsString('Previous delivery', $xml);
		self::assertStringContainsString('People|Portrait', $xml);
		self::assertStringNotContainsString('selection-1', $xml);
		self::assertStringContainsString('archive:AssetId="asset-42"', $xml);
		self::assertSame(1, substr_count($xml, '>Proofing<'));
		self::assertStringContainsString('>Portrait<', $xml);
	}

	public function testXmpParserRejectsDoctypeDeclarations(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->invoke($this->service(), 'loadXmp', '<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><x>&leak;</x>');
	}

	public function testPublicProjectionCannotExposeGpsEvenWhenRequested(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturn('source-etag');
		$stored = $this->createMock(IFilesMetadata::class);
		$stored->method('hasKey')->willReturn(true);
		$stored->method('getEtag')->willReturn('source-etag');
		$stored->method('getArray')->willReturn([
			'camera' => 'Example Camera',
			'gps' => ['latitude' => 52.52, 'longitude' => 13.405],
		]);
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->willReturn($stored);
		$service = new MediaMetadataService($manager, new PolicyService($this->createMock(IConfig::class)));

		self::assertSame(
			['state' => 'ready', 'camera' => 'Example Camera'],
			$service->publicSummary($file, ['camera', 'gps']),
		);
	}

	public function testGpsCoordinatesAreNormalizedFromExifFractions(): void {
		$gps = $this->invoke($this->service(), 'gps', [
			'gpslatitude' => ['52/1', '31/1', '12/1'],
			'gpslatituderef' => 'N',
			'gpslongitude' => ['13/1', '24/1', '18/1'],
			'gpslongituderef' => 'E',
		]);

		self::assertSame(['latitude' => 52.52, 'longitude' => 13.405], $gps);
	}

	private function service(): MediaMetadataService {
		return new MediaMetadataService(
			$this->createMock(IFilesMetadataManager::class),
			new PolicyService($this->createMock(IConfig::class)),
		);
	}

	private function invoke(MediaMetadataService $service, string $method, mixed ...$arguments): mixed {
		$reflection = new \ReflectionMethod($service, $method);
		$reflection->setAccessible(true);
		return $reflection->invoke($service, ...$arguments);
	}
}
