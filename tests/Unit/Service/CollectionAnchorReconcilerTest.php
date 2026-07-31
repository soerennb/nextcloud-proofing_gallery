<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\CollectionAnchorReconciler;
use OCA\ProofingGallery\Service\CollectionAnchorReferences;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class CollectionAnchorReconcilerTest extends TestCase {
	public function testDeletesOnlyOldEmptyUnreferencedCanonicalAnchor(): void {
		$eligible = $this->anchor(1, str_repeat('a', 32), 100_000, []);
		$eligible->expects(self::once())->method('delete');
		$referenced = $this->anchor(2, str_repeat('b', 32), 100_000, []);
		$referenced->expects(self::never())->method('delete');
		$recent = $this->anchor(3, str_repeat('c', 32), 199_000, []);
		$recent->expects(self::never())->method('delete');
		$content = $this->createMock(Node::class);
		$nonEmpty = $this->anchor(4, str_repeat('d', 32), 100_000, [$content]);
		$nonEmpty->expects(self::never())->method('delete');
		$irregular = $this->anchor(5, 'keep-user-files', 100_000, []);
		$irregular->expects(self::never())->method('delete');

		$result = $this->service([$eligible, $referenced, $recent, $nonEmpty, $irregular], [2])->reconcile(false);

		self::assertSame(5, $result['anchors']);
		self::assertSame(1, $result['candidates']);
		self::assertSame(1, $result['deleted']);
		self::assertSame(1, $result['referenced']);
		self::assertSame(1, $result['recent']);
		self::assertSame(1, $result['nonEmpty']);
		self::assertSame(1, $result['ignored']);
	}

	public function testDryRunReportsCandidateWithoutDeletingOrAdvancingCursor(): void {
		$eligible = $this->anchor(10, str_repeat('e', 32), 100_000, []);
		$eligible->expects(self::never())->method('delete');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$config->expects(self::never())->method('setAppValue');

		$result = $this->service([$eligible], [], $config)->reconcile(true);

		self::assertSame(1, $result['dryRun']);
		self::assertSame(1, $result['candidates']);
		self::assertSame(0, $result['deleted']);
	}

	/** @param list<Folder> $anchors @param list<int> $referencedIds */
	private function service(array $anchors, array $referencedIds, ?IConfig $config = null): CollectionAnchorReconciler {
		$collections = $this->createMock(Folder::class);
		$collections->method('getDirectoryListing')->willReturn($anchors);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('.proofing-gallery/collections')->willReturn(true);
		$userFolder->method('get')->with('.proofing-gallery/collections')->willReturn($collections);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('owner')->willReturn($userFolder);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('owner');
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->with('', 100, 0)->willReturn([$user]);
		$references = $this->createMock(CollectionAnchorReferences::class);
		$references->method('isReferenced')->willReturnCallback(
			static fn (int $folderId): bool => in_array($folderId, $referencedIds, true),
		);
		$config ??= $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(200_000);

		return new CollectionAnchorReconciler($root, $users, $references, $config, $clock);
	}

	/** @param list<Node> $children */
	private function anchor(int $id, string $name, int $mtime, array $children): Folder {
		$anchor = $this->createMock(Folder::class);
		$anchor->method('getId')->willReturn($id);
		$anchor->method('getName')->willReturn($name);
		$anchor->method('getMTime')->willReturn($mtime);
		$anchor->method('getDirectoryListing')->willReturn($children);
		return $anchor;
	}
}
