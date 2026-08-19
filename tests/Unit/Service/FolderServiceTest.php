<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Service\MediaCleanupService;
use OCA\ProofingGallery\Service\EmbeddedMetadataExtractor;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCA\ProofingGallery\Service\UploadLockService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IAppData;
use OCP\Files\Node;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class FolderServiceTest extends TestCase {
	public function testSearchesBeforeApplyingNaturalSortAndPagination(): void {
		$nodes = [
			$this->file(1, 'unrelated.jpg', 'image/jpeg'),
			$this->file(2, 'proof-10.jpg', 'image/jpeg'),
			$this->file(3, 'Proof-2.png', 'image/png'),
			$this->folder(4, 'proofs'),
			$this->file(5, 'proof-not-supported.txt', 'text/plain'),
			$this->file(6, '.proof-hidden.jpg', 'image/jpeg'),
		];
		$service = $this->service($nodes);

		$page = $service->listMedia('owner', 42, 2, 1, '', 'PROOF');

		self::assertSame(3, $page->total);
		self::assertSame(2, $page->limit);
		self::assertSame(1, $page->offset);
		self::assertSame(['proof-10.jpg', 'proofs'], array_map(
			static fn ($item): string => $item->name,
			$page->items,
		));
	}

	public function testClampsPageSizeToTwoHundredItems(): void {
		$nodes = [];
		for ($index = 0; $index < 205; $index++) {
			$nodes[] = $this->file($index + 1, sprintf('image-%03d.jpg', $index), 'image/jpeg');
		}

		$page = $this->service($nodes)->listMedia('owner', 42, 500);

		self::assertSame(205, $page->total);
		self::assertSame(200, $page->limit);
		self::assertCount(200, $page->items);
	}

	public function testSortsFilesByModifiedTimeDescendingWhileKeepingFoldersFirst(): void {
		$old = $this->file(1, 'old.jpg', 'image/jpeg', 100);
		$new = $this->file(2, 'new.jpg', 'image/jpeg', 300);
		$page = $this->service([$old, $this->folder(3, 'folder'), $new])
			->listMedia('owner', 42, 20, 0, '', '', 'modified', 'desc');

		self::assertSame(['folder', 'new.jpg', 'old.jpg'], array_map(
			static fn ($item): string => $item->name,
			$page->items,
		));
	}

	public function testRejectsUnknownSort(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service([])->listMedia('owner', 42, sortBy: 'rating');
	}

	public function testFindsOnlyExactUploadConflictsInDestination(): void {
		$service = $this->service([
			$this->file(1, 'proof.jpg', 'image/jpeg'),
			$this->folder(2, 'proofs'),
		]);

		$conflicts = $service->uploadConflicts('owner', 42, '', ['missing.jpg', 'proof.jpg', 'proofs']);

		self::assertSame(['proof.jpg', 'proofs'], array_keys($conflicts));
		self::assertSame(1, $conflicts['proof.jpg']->id);
		self::assertTrue($conflicts['proofs']->folder);
	}

	public function testStagesTheCompleteFileBeforeAcquiringTheDestinationLock(): void {
		$staged = false;
		$stagingName = '';
		$currentName = '';
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(91);
		$file->method('getName')->willReturnCallback(static function () use (&$currentName): string { return $currentName; });
		$file->method('getMimeType')->willReturn('image/png');
		$file->method('getSize')->willReturn(3);
		$file->method('getMTime')->willReturn(1_700_000_000);
		$file->method('getEtag')->willReturn('staged-etag');
		$file->method('move')->willReturnCallback(static function (string $destination) use (&$currentName): void {
			$currentName = basename($destination);
		});

		$target = $this->createMock(Folder::class);
		$target->method('isReadable')->willReturn(true);
		$target->method('isUpdateable')->willReturn(true);
		$target->method('getPath')->willReturn('/owner/files/gallery');
		$target->method('nodeExists')->willReturnCallback(static function (string $name) use (&$staged, &$stagingName): bool {
			return $staged && $name === $stagingName;
		});
		$target->method('newFile')->willReturnCallback(static function (string $name) use (&$staged, &$stagingName, &$currentName, $file): File {
			$staged = true;
			$stagingName = $name;
			$currentName = $name;
			return $file;
		});
		$target->method('get')->willReturn($file);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(42)->willReturn([$target]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('owner')->willReturn($userFolder);
		$provider = $this->createMock(ILockingProvider::class);
		$provider->expects(self::once())->method('acquireLock')->willReturnCallback(static function () use (&$staged): void {
			self::assertTrue($staged, 'The destination lock must be acquired after the full file was staged');
		});
		$provider->expects(self::once())->method('releaseLock');
		$policies = new PolicyService($this->createMock(IConfig::class));
		$service = new FolderService(
			$root,
			new MediaMetadataService($this->createMock(IFilesMetadataManager::class), $policies, new EmbeddedMetadataExtractor($policies)),
			new MediaTypePolicy(),
			new UploadLockService($provider),
			new MediaCleanupService($this->createMock(IDBConnection::class), $this->createMock(IAppData::class)),
		);
		$temporaryPath = tempnam(sys_get_temp_dir(), 'proofing-test-');
		self::assertIsString($temporaryPath);
		file_put_contents($temporaryPath, 'png');
		try {
			$item = $service->uploadMedia('owner', 42, '', 'proof.png', $temporaryPath, 'rename');
		} finally {
			unlink($temporaryPath);
		}

		self::assertSame('proof.png', $item?->name);
	}

	/** @param list<Node> $nodes */
	private function service(array $nodes): FolderService {
		$current = $this->createMock(Folder::class);
		$current->method('isReadable')->willReturn(true);
		$current->method('getDirectoryListing')->willReturn($nodes);
		$byName = [];
		foreach ($nodes as $node) $byName[$node->getName()] = $node;
		$current->method('nodeExists')->willReturnCallback(static fn (string $name): bool => isset($byName[$name]));
		$current->method('get')->willReturnCallback(static fn (string $name): Node => $byName[$name]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(42)->willReturn([$current]);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('owner')->willReturn($userFolder);

		$policies = new PolicyService($this->createMock(IConfig::class));
		$metadata = new MediaMetadataService(
			$this->createMock(IFilesMetadataManager::class),
			$policies,
			new EmbeddedMetadataExtractor($policies),
		);

		$provider = $this->createMock(ILockingProvider::class);
		$cleanup = new MediaCleanupService($this->createMock(IDBConnection::class), $this->createMock(IAppData::class));
		return new FolderService($root, $metadata, new MediaTypePolicy(), new UploadLockService($provider), $cleanup);
	}

	private function file(int $id, string $name, string $mime, int $modifiedAt = 1_700_000_000): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getSize')->willReturn(10);
		$file->method('getMTime')->willReturn($modifiedAt);
		$file->method('getEtag')->willReturn('etag-' . $id);
		return $file;
	}

	private function folder(int $id, string $name): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn($name);
		$folder->method('getMimeType')->willReturn('httpd/unix-directory');
		$folder->method('getSize')->willReturn(0);
		$folder->method('getMTime')->willReturn(1_700_000_000);
		$folder->method('getEtag')->willReturn('etag-' . $id);
		return $folder;
	}
}
