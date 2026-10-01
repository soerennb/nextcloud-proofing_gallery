<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Service\PublicLinkScopeService;
use OCA\ProofingGallery\Service\SourceScopeMapper;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

final class SourceScopeMapperTest extends TestCase {
	public function testWholeSourceLinksRemainWholeSourceLinks(): void {
		$root = $this->root([]);
		$mapping = $this->map(new PublicLink(), null, $root);
		self::assertSame($root, $mapping->target);
		self::assertFalse($mapping->unavailable());
	}

	public function testSingleFolderMapsTheExactRelativePath(): void {
		$link = new PublicLink(); $link->setStartPath('Clients/Anna');
		$target = $this->folder('/new/Clients/Anna');
		$mapping = $this->map($link, null, $this->root(['Clients/Anna' => $target]));
		self::assertSame($target, $mapping->target);
		self::assertSame('Clients/Anna', $mapping->roots[0]['path']);
	}

	public function testPartialMappingKeepsRolesAndOrderAndReportsMissingPaths(): void {
		$link = $this->multi();
		$first = $this->folder('/new/Shared'); $last = $this->folder('/new/Clients/Anna');
		$mapping = $this->map($link, null, $this->root(['Shared' => $first, 'Clients/Anna' => $last]), [
			['folderId' => 10, 'pathSnapshot' => 'Shared', 'role' => 'shared'],
			['folderId' => 11, 'pathSnapshot' => 'Absent', 'role' => 'group'],
			['folderId' => 12, 'pathSnapshot' => 'Clients/Anna', 'role' => 'private'],
		]);
		self::assertSame(['Shared', 'Clients/Anna'], array_column($mapping->roots, 'path'));
		self::assertSame(['shared', 'private'], array_column($mapping->roots, 'role'));
		self::assertSame(['Absent'], $mapping->missing);
		self::assertFalse($mapping->unavailable());
	}

	public function testCurrentRenamedFolderPathOverridesTheSnapshot(): void {
		$old = $this->root([], '/old');
		$old->method('getById')->with(10)->willReturn([$this->folder('/old/Renamed')]);
		$mapping = $this->map($this->multi(), $old, $this->root(['Renamed' => $this->folder('/new/Renamed')]), [['folderId' => 10, 'pathSnapshot' => 'Previous', 'role' => 'private']]);
		self::assertSame('Renamed', $mapping->roots[0]['path']);
	}

	public function testRenamedSingleFolderAlsoUsesItsCurrentPath(): void {
		$link = new PublicLink(); $link->setStartPath('Previous');
		$old = $this->root([], '/old');
		$old->method('getById')->with(10)->willReturn([$this->folder('/old/Renamed')]);
		$target = $this->folder('/new/Renamed');
		self::assertSame($target, $this->map($link, $old, $this->root(['Renamed' => $target]), [['folderId' => 10, 'pathSnapshot' => 'Previous', 'role' => 'shared']])->target);
	}

	public function testNoMatchingRootsNeverFallBackToTheSource(): void {
		$link = $this->multi(); $link->setAllowedRootList(['Missing']);
		$mapping = $this->map($link, null, $this->root([]));
		self::assertTrue($mapping->unavailable());
		self::assertNull($mapping->target);
		self::assertSame(['Missing'], $mapping->missing);
	}

	public function testUnreadableOrForeignFoldersAreMissing(): void {
		$link = $this->multi(); $link->setAllowedRootList(['Unreadable', 'Outside']);
		$unreadable = $this->folder('/new/Unreadable', false);
		$mapping = $this->map($link, null, $this->root(['Unreadable' => $unreadable, 'Outside' => $this->folder('/foreign/Outside')]));
		self::assertTrue($mapping->unavailable());
		self::assertSame(['Unreadable', 'Outside'], $mapping->missing);
	}

	public function testEmptyRestrictedPathCannotExpandAccess(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->map($this->multi(), null, $this->root([]), [['folderId' => 10, 'pathSnapshot' => '', 'role' => 'shared']]);
	}

	public function testTraversalSnapshotsAreRejected(): void {
		$link = $this->multi(); $link->setAllowedRootList(['../private']);
		$this->expectException(NotFoundException::class);
		$this->map($link, null, $this->root([]));
	}

	private function multi(): PublicLink { $link = new PublicLink(); $link->setScopeMode('nodes'); return $link; }
	private function map(PublicLink $link, ?Folder $old, Folder $new, array $rows = []): \OCA\ProofingGallery\Dto\SourceScopeMapping {
		return (new SourceScopeMapper(new PublicLinkScopeService()))->map($link, $old, $new, $rows);
	}
	private function folder(string $path, bool $readable = true): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('isReadable')->willReturn($readable);
		return $folder;
	}
	private function root(array $children, string $path = '/new'): Folder {
		$root = $this->folder($path);
		$root->method('get')->willReturnCallback(static fn (string $name) => $children[$name] ?? throw new NotFoundException());
		$root->method('isSubNode')->willReturnCallback(static fn ($node): bool => str_starts_with($node->getPath(), $path . '/'));
		return $root;
	}
}
