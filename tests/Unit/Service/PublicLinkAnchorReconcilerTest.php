<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\PublicLinkAnchorReconciler;
use OCA\ProofingGallery\Service\PublicLinkAnchorReferences;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class PublicLinkAnchorReconcilerTest extends TestCase {
	public function testDeletesOnlyOldEmptyUnreferencedAnchors(): void {
		$eligible = $this->anchor(1, 100_000, []);
		$eligible->expects(self::once())->method('delete');
		$referenced = $this->anchor(2, 100_000, []);
		$referenced->expects(self::never())->method('delete');
		$recent = $this->anchor(3, 199_000, []);
		$recent->expects(self::never())->method('delete');
		$nonEmpty = $this->anchor(4, 100_000, [$this->createMock(Folder::class)]);
		$nonEmpty->expects(self::never())->method('delete');

		$anchors = $this->createMock(Folder::class);
		$anchors->method('getDirectoryListing')->willReturn([$eligible, $referenced, $recent, $nonEmpty]);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('.proofing-gallery/public-link-anchors')->willReturn(true);
		$userFolder->method('get')->willReturn($anchors);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('owner');
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn([$user]);
		$references = $this->createMock(PublicLinkAnchorReferences::class);
		$references->method('isAnchorReferenced')->willReturnCallback(static fn (int $id): bool => $id === 2);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(200_000);

		$result = (new PublicLinkAnchorReconciler($root, $users, $references, $config, $clock))->reconcile();

		self::assertSame(4, $result['anchors']);
		self::assertSame(1, $result['deleted']);
		self::assertSame(1, $result['referenced']);
		self::assertSame(2, $result['retained']);
	}

	/** @param list<Folder> $children */
	private function anchor(int $id, int $mtime, array $children): Folder {
		$anchor = $this->createMock(Folder::class);
		$anchor->method('getId')->willReturn($id);
		$anchor->method('getMTime')->willReturn($mtime);
		$anchor->method('getDirectoryListing')->willReturn($children);
		return $anchor;
	}
}
