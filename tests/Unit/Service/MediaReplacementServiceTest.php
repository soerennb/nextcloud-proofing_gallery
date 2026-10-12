<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Service\GalleryMediaCountInvalidator;
use OCA\ProofingGallery\Service\MediaCleanupService;
use OCA\ProofingGallery\Service\MediaReplacementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MediaReplacementServiceTest extends TestCase {
	public function testSidecarFailureRestoresBothMembersOfAMove(): void {
		$filePath = '/gallery/photo.jpg'; $sidecarPath = '/gallery/photo.xmp';
		$file = $this->file(1, $filePath);
		$sidecar = $this->file(2, $sidecarPath);
		$sidecar->method('move')->willReturnCallback(static function (string $path) use (&$sidecarPath): void {
			if ($path === '/gallery/renamed.xmp') throw new \RuntimeException('XMP move failed');
			$sidecarPath = $path;
		});
		try {
			$this->service()->movePair($file, $sidecar, '/gallery/renamed.jpg', '/gallery/renamed.xmp');
			self::fail('The sidecar failure must propagate');
		} catch (\RuntimeException $error) { self::assertSame('XMP move failed', $error->getMessage()); }
		self::assertSame('/gallery/photo.jpg', $filePath);
		self::assertSame('/gallery/photo.xmp', $sidecarPath);
	}

	public function testReceiptFailureRetainsTheCommittedFileAndBothBackups(): void {
		$stagedPath = '/gallery/.photo.upload-abcd.jpg';
		$originalPath = '/gallery/photo.jpg'; $sidecarPath = '/gallery/photo.xmp';
		$file = $this->file(3, $stagedPath);
		$original = $this->file(1, $originalPath); $sidecar = $this->file(2, $sidecarPath);
		foreach ([$file, $original, $sidecar] as $node) $node->expects(self::never())->method('delete');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::never())->method('add');
		try {
			$this->service($jobs)->replace('owner', $file, $original, $sidecar, $this->target(), 'photo.jpg', '.photo.upload-abcd.jpg', static function (): never { throw new \RuntimeException('Receipt unavailable'); });
			self::fail('Receipt failure must propagate');
		} catch (\RuntimeException $error) { self::assertSame('Receipt unavailable', $error->getMessage()); }
		self::assertSame('/gallery/photo.jpg', $stagedPath);
		self::assertSame('/gallery/.photo.jpg.replaced-3', $originalPath);
		self::assertSame('/gallery/.photo.xmp.replaced-3', $sidecarPath);
	}

	public function testCleanupFailureCannotUndoAcknowledgedMedia(): void {
		$stagedPath = '/gallery/.photo.upload-abcd.jpg'; $originalPath = '/gallery/photo.jpg';
		$file = $this->file(3, $stagedPath); $original = $this->file(1, $originalPath);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willThrowException(new \RuntimeException('Cleanup queue unavailable'));
		$acknowledgedId = null;
		$this->service($jobs)->replace('owner', $file, $original, null, $this->target(), 'photo.jpg', '.photo.upload-abcd.jpg', static function (File $committed) use (&$acknowledgedId): void { $acknowledgedId = $committed->getId(); });
		self::assertSame(3, $acknowledgedId);
		self::assertSame('/gallery/photo.jpg', $stagedPath);
		self::assertSame('/gallery/.photo.jpg.replaced-3', $originalPath);
	}

	public function testBackupDeletionFailureAfterDatabaseCleanupRetainsItsRetryAndNewFile(): void {
		$db = $this->createMock(IDBConnection::class);
		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'delete', 'update', 'set'] as $method) $query->method($method)->willReturnSelf();
		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$query->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($query);
		$purged = false;
		$db->expects(self::once())->method('commit')->willReturnCallback(static function () use (&$purged): void { $purged = true; });
		$stagedPath = '/gallery/.photo.upload-abcd.jpg'; $originalPath = '/gallery/photo.jpg';
		$file = $this->file(3, $stagedPath); $original = $this->file(1, $originalPath);
		$file->expects(self::never())->method('delete');
		$original->expects(self::once())->method('delete')->willReturnCallback(static function () use (&$purged): never {
			self::assertTrue($purged, 'Failure must occur after application references were committed as deleted');
			throw new \RuntimeException('Backup storage unavailable');
		});
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::once())->method('add');
		$jobs->expects(self::never())->method('remove');
		$acknowledged = 0;
		$this->service($jobs, $db)->replace('owner', $file, $original, null, $this->target(), 'photo.jpg', '.photo.upload-abcd.jpg', static function () use (&$acknowledged): void { $acknowledged++; });
		self::assertSame(1, $acknowledged);
		self::assertSame('/gallery/photo.jpg', $stagedPath);
		self::assertSame('/gallery/.photo.jpg.replaced-3', $originalPath);
	}

	private function file(int $id, string &$path): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturnCallback(static function () use (&$path): string { return $path; });
		$file->method('move')->willReturnCallback(static function (string $destination) use (&$path): void { $path = $destination; });
		return $file;
	}

	private function target(): Folder {
		$target = $this->createMock(Folder::class);
		$target->method('getPath')->willReturn('/gallery');
		return $target;
	}

	private function service(?IJobList $jobs = null, ?IDBConnection $db = null): MediaReplacementService {
		$db ??= $this->createMock(IDBConnection::class);
		$jobs ??= $this->createMock(IJobList::class);
		$counts = new GalleryMediaCountInvalidator(new GalleryMediaCountRepository($db), new CollectionRepository($db), $jobs, $this->createMock(ITimeFactory::class));
		return new MediaReplacementService(new MediaCleanupService($db, $counts, $this->createMock(IAppData::class)), $jobs, $this->createMock(LoggerInterface::class));
	}
}
