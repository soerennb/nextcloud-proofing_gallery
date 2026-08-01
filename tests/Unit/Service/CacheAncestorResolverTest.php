<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\CacheAncestorResolver;
use OCP\Files\Cache\ICache;
use PHPUnit\Framework\TestCase;

final class CacheAncestorResolverTest extends TestCase {
	public function testReturnsExistingNodeAndParentIdsWithoutDuplicates(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::exactly(4))->method('getId')->willReturnMap([
			['clients/ada/photo.jpg', 30],
			['clients/ada', 20],
			['clients', 10],
			['', 1],
		]);
		self::assertSame([30, 20, 10, 1], (new CacheAncestorResolver())->folderIds($cache, '/clients/ada/photo.jpg'));
	}

	public function testRemovedLeafCanStillResolveItsExistingParents(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('getId')->willReturnMap([
			['clients/removed.jpg', 0],
			['clients', 10],
			['', 1],
		]);
		self::assertSame([10, 1], (new CacheAncestorResolver())->folderIds($cache, 'clients/removed.jpg'));
	}
}
