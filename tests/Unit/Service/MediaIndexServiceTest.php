<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Db\MediaIndexMapper;
use OCA\ProofingGallery\Db\MediaIndexScanRepository;
use OCA\ProofingGallery\Db\MediaSortRepository;
use OCA\ProofingGallery\Service\EmbeddedMetadataExtractor;
use OCA\ProofingGallery\Service\FolderMediaReader;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\GalleryMediaCountInvalidator;
use OCA\ProofingGallery\Service\MediaCleanupService;
use OCA\ProofingGallery\Service\MediaCursorCodec;
use OCA\ProofingGallery\Service\MediaIndexService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Service\MediaReplacementService;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\UploadLockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Cache\ICache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Storage\IStorage;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MediaIndexServiceTest extends TestCase {
	public function testFailedWriteRollsBackTheBatchBeforeAdvancingItsCursor(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('rollBack');
		$db->expects(self::never())->method('commit');
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->query());
		$root = $this->root();
		$files = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$root]);
		$files->method('getUserFolder')->willReturn($userFolder);
		$policies = new PolicyService($this->createMock(IConfig::class));
		$metadata = new MediaMetadataService($this->createMock(IFilesMetadataManager::class), $policies, new EmbeddedMetadataExtractor($policies), $this->createMock(IEventDispatcher::class));
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::never())->method('add');
		$clock = $this->createMock(ITimeFactory::class);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects(self::once())->method('acquireLock');
		$locks->expects(self::once())->method('releaseLock');
		$counts = new GalleryMediaCountInvalidator(new GalleryMediaCountRepository($db), new CollectionRepository($db), $jobs, $clock);
		$cleanup = new MediaCleanupService($db, $counts, $this->createMock(IAppData::class));
		$folders = new FolderService($files, $metadata, new MediaTypePolicy(), new UploadLockService($locks), $cleanup, new MediaReplacementService($cleanup, $jobs, $this->createMock(LoggerInterface::class)));
		$scans = new MediaIndexScanRepository($db);
		$reader = new FolderMediaReader($scans, new MediaTypePolicy(), $this->createMock(IMountManager::class));
		$service = new MediaIndexService(new MediaIndexMapper($db), $folders, $policies, $clock, new MediaCursorCodec($this->createMock(ICrypto::class)), $scans, $jobs, $locks, $metadata, new MediaSortRepository($db), $reader, $db);
		$gallery = new Gallery();
		$gallery->setId(7); $gallery->setSourceType('folder'); $gallery->setOwnerUid('owner'); $gallery->setFolderId(1);
		$this->expectExceptionMessage('Index write failed');
		$service->continueRebuild($gallery);
	}

	private function query(): IQueryBuilder {
		$query = $this->createMock(IQueryBuilder::class);
		$table = '';
		foreach (['select', 'set', 'innerJoin', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $method) $query->method($method)->willReturnSelf();
		foreach (['from', 'update'] as $method) $query->method($method)->willReturnCallback(static function (string $name) use (&$table, $query): IQueryBuilder { $table = $name; return $query; });
		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$query->method('executeStatement')->willReturnCallback(static function () use (&$table): never {
			self::assertSame('proofing_media_index', $table, 'No scan cursor or progress may advance before index persistence');
			throw new \RuntimeException('Index write failed');
		});
		$query->method('executeQuery')->willReturnCallback(function () use (&$table): IResult {
			$rows = match ($table) {
				'proofing_media_scans' => [['status' => 'running', 'generation' => 'generation', 'indexed_count' => 0, 'truncated' => false, 'dirty' => false]],
				'proofing_media_scan_queue' => [['id' => 8, 'relative_path' => '', 'after_file_id' => 0, 'depth' => 0]],
				'filecache' => [['fileid' => 3, 'parent' => 1, 'name' => 'photo.jpg', 'mimetype' => 'image/jpeg', 'size' => 1, 'mtime' => 1, 'etag' => 'etag']],
				default => [],
			};
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$rows): array|false { return array_shift($rows) ?? false; });
			$result->method('fetchAll')->willReturn($rows);
			return $result;
		});
		return $query;
	}

	private function root(): Folder {
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(1);
		$root->method('getPath')->willReturn('/owner/files/root');
		$root->method('isReadable')->willReturn(true);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(3); $file->method('getName')->willReturn('photo.jpg');
		$file->method('getPath')->willReturn('/owner/files/root/photo.jpg');
		$file->method('getParent')->willReturn($root);
		$file->method('getMimeType')->willReturn('image/jpeg'); $file->method('getEtag')->willReturn('etag');
		$file->method('isReadable')->willReturn(true);
		$root->method('get')->willReturn($file);
		$cache = $this->createMock(ICache::class); $cache->method('getNumericStorageId')->willReturn(42);
		$storage = $this->createMock(IStorage::class); $storage->method('getCache')->willReturn($cache);
		$root->method('getStorage')->willReturn($storage);
		return $root;
	}
}
