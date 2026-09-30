<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\PublicLinkAnchorService;
use OCA\ProofingGallery\Service\PublicLinkScopeService;
use OCA\ProofingGallery\Service\PublicShareTargetService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicShareTargetServiceTest extends TestCase {
	#[DataProvider('anchorScopes')]
	public function testPrimaryScopedSharesUseTheirAnchorInsteadOfTheGalleryRoot(string $scopeMode, array $roots): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getPath')->willReturn('/owner/files');
		$anchor = $this->createMock(Folder::class);
		$anchor->method('getPath')->willReturn('/owner/files/.proofing-gallery/public-link-anchors/anchor');
		$userFolder->expects(self::once())->method('getById')->with(42)->willReturn([$anchor]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('owner')->willReturn($userFolder);
		$gallery = new Gallery();
		$gallery->setOwnerUid('owner');
		$gallery->setFolderId(7);
		$link = new PublicLink();
		$link->setIsPrimary(true);
		$link->setScopeMode($scopeMode);
		$link->setAllowedRootList($roots);
		$link->setScopeAnchorId(42);
		self::assertSame($anchor, $this->service($root)->resolve($gallery, $link));
	}

	public static function anchorScopes(): array {
		return [['nodes', ['shared', 'private']], ['empty', []], ['legacy', ['shared', 'private']]];
	}

	public function testMissingAnchorCannotFallBackToTheGalleryRoot(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::never())->method('getUserFolder');
		$link = new PublicLink();
		$link->setScopeMode('nodes');
		$this->expectException(\InvalidArgumentException::class);
		$this->service($root)->resolve(new Gallery(), $link);
	}

	public function testSingleFolderLinkUsesItsExistingSubfolder(): void {
		$target = $this->createMock(Folder::class);
		$galleryFolder = $this->createMock(Folder::class);
		$galleryFolder->method('isReadable')->willReturn(true);
		$galleryFolder->expects(self::once())->method('get')->with('client/final')->willReturn($target);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(7)->willReturn([$galleryFolder]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$gallery = new Gallery(); $gallery->setOwnerUid('owner'); $gallery->setFolderId(7);
		$link = new PublicLink(); $link->setStartPath('client/final');
		self::assertSame($target, $this->service($root)->resolve($gallery, $link));
	}

	private function service(IRootFolder $root): PublicShareTargetService {
		// Target resolution only needs FolderService's root-folder collaborator.
		$folders = (new \ReflectionClass(FolderService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(FolderService::class, 'rootFolder'))->setValue($folders, $root);
		return new PublicShareTargetService($folders, new PublicLinkAnchorService($root), new PublicLinkScopeService());
	}
}
