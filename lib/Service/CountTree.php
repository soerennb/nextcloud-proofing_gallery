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
}
