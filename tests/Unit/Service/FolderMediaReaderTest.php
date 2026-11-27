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
	public function testIgnoresHiddenUnsupportedAndUnreadableFiles(): void {
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

	public function testResolvesASmallFolderFromOneListingWithoutPerChildLookups(): void {
		$files = [$this->file(2, 'a.jpg', 'image/jpeg'), $this->file(3, 'b.jpg', 'image/jpeg'), $this->file(4, 'c.jpg', 'image/jpeg')];
		$root = $this->folder(1, '/root', 7, $files, listings: 1, lookups: 0);
		self::assertSame($files, $this->reader($files, 7, 1)->page($root, '', 0, 500)['items']);
	}

	public function testReusesTheListingForLaterPagesOfTheSameFolder(): void {
		$files = [];
		for ($i = 2; $i < 8; $i++) $files[] = $this->file($i, "img$i.jpg", 'image/jpeg');
		$root = $this->folder(1, '/root', 7, $files, listings: 1, lookups: 0);
		$reader = $this->reader($files, 7, 1);
		$first = $reader->page($root, '', 0, 3);
		$second = $reader->page($root, '', $first['after'], 3);
		$last = $reader->page($root, '', $second['after'], 3);
		self::assertSame($files, [...$first['items'], ...$second['items'], ...$last['items']]);
		self::assertTrue($last['done']);
	}

	public function testResolvesNamesMissingFromTheListingDirectly(): void {
		$listed = $this->file(2, 'a.jpg', 'image/jpeg');
		$added = $this->file(3, 'b.jpg', 'image/jpeg');
		$root = $this->folder(1, '/root', 7, [$listed, $added], listings: 1, lookups: 1, listing: [$listed]);
		self::assertSame([$listed, $added], $this->reader([$listed, $added], 7, 1)->page($root, '', 0, 500)['items']);
	}

	public function testFoldersAboveTheListingLimitAreResolvedPerChild(): void {
		$files = [];
		for ($i = 2; $i < 8; $i++) $files[] = $this->file($i, "img$i.jpg", 'image/jpeg');
		$root = $this->folder(1, '/root', 7, $files, listings: 0, lookups: 3);
		$page = $this->reader($files, 7, 1, listingLimit: 4)->page($root, '', 0, 3);
		self::assertSame(array_slice($files, 0, 3), $page['items']);
		self::assertFalse($page['done']);
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

	/**
	 * @param list<Node> $nodes
	 * @param list<Node>|null $listing
	 */
	private function folder(int $id, string $path, int $storageId, array $nodes, ?int $listings = null, ?int $lookups = null, ?array $listing = null): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getPath')->willReturn($path);
		$folder->method('getEtag')->willReturn('etag-' . $id);
		$folder->method('isReadable')->willReturn(true);
		$folder->method('isSubNode')->willReturn(true);
		$folder->expects($listings === null ? self::any() : self::exactly($listings))->method('getDirectoryListing')->willReturn($listing ?? $nodes);
		$folder->expects($lookups === null ? self::any() : self::exactly($lookups))->method('get')->willReturnCallback(static function (string $name) use ($nodes): Node {
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

	/**
	 * Serves children() pages (fileid > after) and the bounded size check from $nodes.
	 * @param list<Node> $nodes
	 */
	private function reader(array $nodes, int $storageId, int $parentId, int $listingLimit = FolderMediaReader::LISTING_LIMIT): FolderMediaReader {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($nodes, $storageId, $parentId): IQueryBuilder {
			$query = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy'] as $method) $query->method($method)->willReturnSelf();
			$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
			$parameters = [];
			$query->method('createNamedParameter')->willReturnCallback(static function ($value) use (&$parameters): string { $parameters[] = $value; return '?'; });
			$limit = 0; $offset = null;
			$query->method('setMaxResults')->willReturnCallback(static function (int $value) use (&$limit, $query): IQueryBuilder { $limit = $value; return $query; });
			$query->method('setFirstResult')->willReturnCallback(static function (int $value) use (&$offset, $query): IQueryBuilder { $offset = $value; return $query; });
			$query->method('executeQuery')->willReturnCallback(function () use (&$parameters, &$limit, &$offset, $nodes, $storageId, $parentId): IResult {
				self::assertSame([$storageId, $parentId], array_slice($parameters, 0, 2));
				self::assertLessThanOrEqual(500, $limit);
				$rows = $offset !== null
					? array_slice($nodes, $offset, 1)
					: array_slice(array_values(array_filter($nodes, static fn (Node $node): bool => $node->getId() > $parameters[2])), 0, $limit);
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnCallback(static function () use (&$rows, $parentId): array|false {
					$node = array_shift($rows);
					return $node === null ? false : ['fileid' => $node->getId(), 'parent' => $parentId, 'name' => $node->getName(), 'mimetype' => $node->getMimeType(), 'size' => 1, 'mtime' => 1, 'etag' => 'test'];
				});
				return $result;
			});
			return $query;
		});
		$mounts = $this->createMock(IMountManager::class);
		$mounts->method('findIn')->willReturn([]);
		return new FolderMediaReader(new MediaIndexScanRepository($db), new MediaTypePolicy(), $mounts, $listingLimit);
	}
}
