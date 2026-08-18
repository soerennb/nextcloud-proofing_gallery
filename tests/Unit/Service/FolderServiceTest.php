<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Service\EmbeddedMetadataExtractor;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCA\ProofingGallery\Service\UploadLockService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IConfig;
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

	/** @param list<Node> $nodes */
	private function service(array $nodes): FolderService {
		$current = $this->createMock(Folder::class);
		$current->method('isReadable')->willReturn(true);
		$current->method('getDirectoryListing')->willReturn($nodes);

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
		return new FolderService($root, $metadata, new MediaTypePolicy(), new UploadLockService($provider));
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
