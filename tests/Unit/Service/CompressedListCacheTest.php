<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\CompressedListCache;
use OCP\ICache;
use PHPUnit\Framework\TestCase;

final class CompressedListCacheTest extends TestCase {
	/** In-memory cache that, like the Redis backend, keeps only JSON-encodable values. */
	private function backend(): ICache {
		$store = new \ArrayObject();
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($store): bool {
			$json = json_encode($value);
			if ($json === false) return false;
			$store[$key] = $json;
			return true;
		});
		$cache->method('get')->willReturnCallback(static fn (string $key): mixed => isset($store[$key]) ? json_decode((string)$store[$key], true) : null);
		return $cache;
	}

	/** @return list<array{id: int, name: string, folder: bool, group: string}> */
	private static function entries(int $count): array {
		$entries = [];
		for ($i = 0; $i < $count; $i++) $entries[] = ['id' => 1000 + $i, 'name' => sprintf('IMG_%05d.jpg', $i), 'folder' => false, 'group' => 'image'];
		return $entries;
	}

	public function testLargeListingsRoundTripThroughAJsonBackend(): void {
		$cache = new CompressedListCache($this->backend());
		$entries = self::entries(25000);

		self::assertTrue($cache->set('order', $entries, 300));
		self::assertSame($entries, $cache->get('order'));
	}

	public function testListingsTooLargeAfterCompressionAreNotStored(): void {
		$cache = new CompressedListCache($this->backend(), 1024);

		self::assertFalse($cache->set('order', self::entries(5000), 300));
		self::assertNull($cache->get('order'));
	}

	public function testMissingOrForeignValuesAreMisses(): void {
		$backend = $this->backend();
		$cache = new CompressedListCache($backend);
		self::assertNull($cache->get('missing'));

		// An uncompressed listing written by an earlier version is rebuilt instead of misread.
		$backend->set('old', [['id' => 1]], 300);
		self::assertNull($cache->get('old'));
		$backend->set('text', 'not base64 !', 300);
		self::assertNull($cache->get('text'));
	}
}
