<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

use OCP\Files\Node;

final class PublicPagePagination {
	/** @param list<array<string, mixed>> $items */
	public static function itemPosition(array $items, int $fileId): ?int {
		foreach ($items as $index => $item) if ((int)($item['id'] ?? 0) === $fileId) return $index;
		return null;
	}

	/** @param list<Node> $nodes */
	public static function nodePosition(array $nodes, int $fileId): ?int {
		foreach ($nodes as $index => $node) if ($node->getId() === $fileId) return $index;
		return null;
	}

	public static function offset(int $total, int $limit, int $requestedOffset, ?int $focusIndex): int {
		if ($focusIndex !== null) return intdiv($focusIndex, $limit) * $limit;
		if ($total === PHP_INT_MAX) return max(0, $requestedOffset);
		$lastOffset = max(0, (intdiv(max(0, $total - 1), $limit)) * $limit);
		return min(max(0, $requestedOffset), $lastOffset);
	}

}
