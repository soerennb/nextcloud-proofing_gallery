<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\MediaSummaryRepository;
use OCA\ProofingGallery\Service\MediaSummaryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MediaSummaryServiceTest extends TestCase {
	public function testCacheFailureFallsBackToDeterministicLiveSummary(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('cache unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$folder->method('getId')->willReturn(5);
		$folder->method('getEtag')->willReturn('folder-etag');
		$subfolder = $this->createMock(Folder::class);
		$subfolder->method('getName')->willReturn('album');
		$folder->method('getDirectoryListing')->willReturn([
			$this->file(8, 'z.mp4', 'video/mp4'),
			$this->file(7, 'b.png', 'image/png'),
			$this->file(6, 'a.jpg', 'image/jpeg'),
			$this->file(9, '.hidden.jpg', 'image/jpeg'),
			$subfolder,
		]);

		$previews = $this->createMock(\OCP\IPreview::class);
		$previews->method('isAvailable')->willReturn(true);
		$summary = (new MediaSummaryService(
			new MediaSummaryRepository($db),
			$this->createMock(ITimeFactory::class),
			$logger,
			new \OCA\ProofingGallery\Service\AutomaticCoverSelector(new \OCA\ProofingGallery\Service\MediaTypePolicy(), $previews),
			(new \ReflectionClass(\OCA\ProofingGallery\Service\GalleryMediaCountInvalidator::class))->newInstanceWithoutConstructor(),
		))->forFolder(4, 5, $folder);

		self::assertSame(0, $summary['total']);
		self::assertSame(6, $summary['coverFileId']);
		self::assertSame('image/jpeg', $summary['coverMimeType']);
	}

	private function file(int $id, string $name, string $mimeType): File {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mimeType);
		return $file;
	}
}
