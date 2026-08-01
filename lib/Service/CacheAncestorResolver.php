<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\Cache\ICache;

final class CacheAncestorResolver {
	/** @return list<int> */
	public function folderIds(ICache $cache, string $path): array {
		$path = trim($path, '/');
		$paths = $path === '' ? [''] : explode('/', $path);
		$ids = [];
		while ($paths !== []) {
			$candidate = implode('/', $paths);
			$id = $cache->getId($candidate);
			if ($id > 0) $ids[] = $id;
			array_pop($paths);
		}
		$rootId = $cache->getId('');
		if ($rootId > 0) $ids[] = $rootId;
		return array_values(array_unique($ids));
	}
}
