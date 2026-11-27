<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

/**
 * Folds the per-folder counts of one count generation into gallery totals.
 *
 * A visited folder contributes its direct files plus everything below it. A folder that was
 * satisfied from its cached subtree contributes the stored subtree totals and is not descended into.
 * Every row is reached from the root through the parent links of this generation only.
 */
final class CountTree {
	/**
	 * @param array<int, array{parent_id: int, hit: bool, direct_images: int, direct_videos: int, direct_witness: ?int, sub_images: int, sub_videos: int, sub_witness: ?int}> $rows
	 *        Rows of the current generation keyed by folder ID. Cache hits carry their stored subtree totals.
	 * @return array{images: int, videos: int, witness: ?int, visited: array<int, array{images: int, videos: int, witness: ?int}>}
	 */
	public static function fold(array $rows, int $rootId): array {
		$children = [];
		foreach ($rows as $id => $row) {
			if ($id === $rootId || $row['parent_id'] === $id) continue;
			$children[$row['parent_id']][] = $id;
		}
		$visited = [];
		$total = self::subtree($rows, $children, $rootId, $visited, []);
		return ['images' => $total['images'], 'videos' => $total['videos'], 'witness' => $total['witness'], 'visited' => $visited];
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, list<int>> $children
	 * @param array<int, array{images: int, videos: int, witness: ?int}> $visited
	 * @param array<int, true> $path guards against cycles in corrupt parent links
	 * @return array{images: int, videos: int, witness: ?int}
	 */
	private static function subtree(array $rows, array $children, int $id, array &$visited, array $path): array {
		$zero = ['images' => 0, 'videos' => 0, 'witness' => null];
		if (!isset($rows[$id]) || isset($path[$id])) return $zero;
		$row = $rows[$id];
		if ($row['hit']) {
			return ['images' => (int)$row['sub_images'], 'videos' => (int)$row['sub_videos'], 'witness' => $row['sub_witness'] === null ? null : (int)$row['sub_witness']];
		}
		$images = (int)$row['direct_images'];
		$videos = (int)$row['direct_videos'];
		$witness = $row['direct_witness'] === null ? null : (int)$row['direct_witness'];
		$path[$id] = true;
		foreach ($children[$id] ?? [] as $child) {
			$sub = self::subtree($rows, $children, $child, $visited, $path);
			$images += $sub['images'];
			$videos += $sub['videos'];
			$witness ??= $sub['witness'];
		}
		$result = ['images' => $images, 'videos' => $videos, 'witness' => $witness];
		$visited[$id] = $result;
		return $result;
	}

	/**
	 * Folder rows no walk can reach again. A row of the current generation stays. An older row stays
	 * only below a cache hit (directly or through other older rows): those subtrees were not walked
	 * and their rows are reused once the subtree changes. Below a folder walked in this generation,
	 * every existing child was visited or carried over, so older rows there belong to deleted or moved
	 * folders. Rows under an old root after a rebind do not reach the current generation either.
	 *
	 * @param array<int, array{parent_id: int, generation: string, hit: bool}> $rows all rows of a gallery keyed by folder ID
	 * @return list<int> folder IDs to delete
	 */
	public static function orphans(array $rows, string $generation): array {
		$kept = [];
		$keep = static function (int $id, array $path) use (&$keep, &$kept, $rows, $generation): bool {
			if (isset($kept[$id])) return $kept[$id];
			$row = $rows[$id];
			if ($row['generation'] === $generation) return $kept[$id] = true;
			$parentId = $row['parent_id'];
			if (!isset($rows[$parentId]) || isset($path[$id])) return $kept[$id] = false;
			$parent = $rows[$parentId];
			$path[$id] = true;
			$underCache = $parent['generation'] !== $generation || $parent['hit'];
			return $kept[$id] = $underCache && $keep($parentId, $path);
		};
		$orphans = [];
		foreach (array_keys($rows) as $id) if (!$keep($id, [])) $orphans[] = $id;
		return $orphans;
	}
}
