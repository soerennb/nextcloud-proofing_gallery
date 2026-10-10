<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\MediaIndexScanRepository;
use OCA\ProofingGallery\Service\FolderMediaReader;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\ICache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class FolderMediaReaderTest extends TestCase {
	public function testIgnoresHiddenUnsupportedAndUnreadableFilesWithoutListingDirectories(): void {
		$files = [
			$this->file(2, 'image.jpg', 'image/jpeg'),
			$this->file(3, '.hidden.jpg', 'image/jpeg'),
			$this->file(4, 'notes.pdf', 'application/pdf'),
			$this->file(5, 'private.jpg', 'image/jpeg', false),
		];
		$root = $this->folder(1, '/root', 7, $files);
		$reader = $this->reader($files, 7, 1);
		$page = $reader->page($root, '', 0, 500);
		self::assertSame([$files[0]], $page['items']);
		self::assertSame(4, $page['examined']);
		self::assertSame(5, $page['after']);
		self::assertTrue($page['done']);
	}

	public function testUsesTheSubfolderStorageAndPreservesThePagingBoundary(): void {
		$file = $this->file(9, 'image.jpg', 'image/jpeg');
		$album = $this->folder(8, '/root/Album', 42, [$file]);
		$root = $this->folder(1, '/root', 7, [$album]);
		$page = $this->reader([$file], 42, 8)->page($root, 'Album', 0, 1);
		self::assertSame([$file], $page['items']);
		self::assertFalse($page['done']);
	}

	public function testReadinessHasAHardEntryBudgetEvenWhenOnlyNonMediaExists(): void {
		$files = [];
		for ($i = 2; $i < 601; $i++) $files[] = $this->file($i, "notes$i.pdf", 'application/pdf');
		$root = $this->folder(1, '/root', 7, $files);
		self::assertFalse($this->reader($files, 7, 1)->hasMedia($root));
	}

	public function testHiddenBranchesCannotSupplyAnExistingReadinessWitness(): void {
		$file = $this->file(2, 'photo.jpg', 'image/jpeg');
		$file->method('getPath')->willReturn('/root/.private/photo.jpg');
		$root = $this->folder(1, '/root', 7, [$file]);
		$reader = $this->reader([], 7, 1);
		self::assertFalse($reader->isVisibleMedia($root, $file));
		self::assertSame([], $reader->page($root, '.private', 0, 500)['items']);
	}

	/** @param list<Node> $nodes */
	private function folder(int $id, string $path, int $storageId, array $nodes): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getPath')->willReturn($path);
		$folder->method('isReadable')->willReturn(true);
		$folder->method('isSubNode')->willReturn(true);
		$folder->expects(self::never())->method('getDirectoryListing');
		$folder->method('get')->willReturnCallback(static function (string $name) use ($nodes): Node {
			foreach ($nodes as $node) if ($node->getName() === $name) return $node;
			throw new \OCP\Files\NotFoundException();
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('getNumericStorageId')->willReturn($storageId);
		$storage = $this->createMock(IStorage::class);
		$storage->method('getCache')->willReturn($cache);
		$folder->method('getStorage')->willReturn($storage);
		return $folder;
	}

	private function file(int $id, string $name, string $mime, bool $readable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('isReadable')->willReturn($readable);
		return $file;
	}

	/** @param list<Node> $nodes */
	private function reader(array $nodes, int $storageId, int $parentId): FolderMediaReader {
		$db = $this->createMock(IDBConnection::class);
		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy'] as $method) $query->method($method)->willReturnSelf();
		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$parameters = [];
		$query->method('createNamedParameter')->willReturnCallback(static function ($value) use (&$parameters): string { $parameters[] = $value; return '?'; });
		$limit = 0;
		$query->method('setMaxResults')->willReturnCallback(static function (int $value) use (&$limit, $query): IQueryBuilder { $limit = $value; return $query; });
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(function () use (&$parameters, &$limit, &$nodes, $storageId, $parentId): array|false {
			self::assertSame([$storageId, $parentId, 0], $parameters);
			self::assertLessThanOrEqual(500, $limit);
			static $position = 0;
			if ($position >= min($limit, count($nodes))) return false;
			$node = $nodes[$position++];
			return ['fileid' => $node->getId(), 'parent' => $parentId, 'name' => $node->getName(), 'mimetype' => $node->getMimeType(), 'size' => 1, 'mtime' => 1, 'etag' => 'test'];
		});
		$query->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($query);
		$mounts = $this->createMock(IMountManager::class);
		$mounts->method('findIn')->willReturn([]);
		return new FolderMediaReader(new MediaIndexScanRepository($db), new MediaTypePolicy(), $mounts);
	}
}
