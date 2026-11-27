<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\ICache;

/**
 * Stores large listings compressed in the distributed cache.
 *
 * Folder orders and collection listings grow with the number of files: 25,000 entries take about
 * 2.7 MB serialized. Memcached rejects items over 1 MB by default, without an error, so such a
 * listing would never be cached. Compressed, the same listing takes about 430 KB. Values are
 * base64 encoded because the Redis backend stores values as JSON, which cannot hold binary data.
 * Listings that stay too large after compression are not cached; callers then build them per request.
 */
final class CompressedListCache {
	/** Below the default Memcached item limit (1 MB), leaving room for the key and item overhead. */
	public const MAX_BYTES = 960 * 1024;

	public function __construct(private ICache $cache, private int $maxBytes = self::MAX_BYTES) {
	}

	/** @return array<mixed>|null */
	public function get(string $key): ?array {
		$stored = $this->cache->get($key);
		if (!is_string($stored)) return null;
		$compressed = base64_decode($stored, true);
		if ($compressed === false) return null;
		$serialized = @gzuncompress($compressed);
		if ($serialized === false) return null;
		$value = unserialize($serialized, ['allowed_classes' => false]);
		return is_array($value) ? $value : null;
	}

	/**
	 * @param array<mixed> $value
	 * @return bool Whether the value was small enough to be stored.
	 */
	public function set(string $key, array $value, int $ttl): bool {
		$compressed = gzcompress(serialize($value), 6);
		if ($compressed === false) return false;
		$stored = base64_encode($compressed);
		if (strlen($stored) > $this->maxBytes) return false;
		return (bool)$this->cache->set($key, $stored, $ttl);
	}
}
