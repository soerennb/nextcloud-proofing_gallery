<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\MediaIndex;
use OCA\ProofingGallery\Db\MediaIndexMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;

final class MediaIndexService {
	private const SUPPORTED_VIDEO_MIMES = ['video/mp4', 'video/webm'];

	public function __construct(
		private MediaIndexMapper $index,
		private IDBConnection $db,
		private FolderService $folders,
		private PolicyService $policies,
		private ITimeFactory $clock,
		private CullingService $culling,
	) {
	}

	/** @return array{indexed: int, removed: int, truncated: bool, generation: string} */
	public function rebuild(Gallery $gallery): array {
		if ($gallery->getSourceType() !== 'folder') throw new \InvalidArgumentException('Only folder galleries can be indexed');
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$rootStorage = $root->getStorage()->getId();
		$generation = bin2hex(random_bytes(16));
		$now = $this->clock->getTime();
		$limit = $this->policies->get('maxIndexedMedia');
		$indexed = 0;
		$truncated = false;
		/** @var list<array{0: Folder, 1: string, 2: int}> $pending */
		$pending = [[$root, '', 0]];

		while ($pending !== [] && !$truncated) {
			[$folder, $folderPath, $depth] = array_pop($pending);
			$nodes = array_values(array_filter(
				$folder->getDirectoryListing(),
				static fn (Node $node): bool => !str_starts_with($node->getName(), '.'),
			));
			usort($nodes, static fn (Node $left, Node $right): int => strnatcasecmp($right->getName(), $left->getName()));
			foreach ($nodes as $node) {
				if (!$node->isReadable() || $node->getStorage()->getId() !== $rootStorage) continue;
				$relativePath = ltrim($folderPath . '/' . $node->getName(), '/');
				if ($node instanceof Folder) {
					$pending[] = [$node, $relativePath, $depth + 1];
					continue;
				}
				if (!$node instanceof File || !$this->isSupported($node)) continue;
				if ($indexed >= $limit) {
					$truncated = true;
					break;
				}
				$this->upsert($gallery->getId(), $node, $folder->getId(), $relativePath, $depth, $generation, $now);
				$indexed++;
			}
		}

		// Never purge unseen rows after a truncated scan; doing so would make a
		// bounded index look authoritative while silently dropping valid media.
		$removed = $truncated ? 0 : $this->index->deleteOtherGenerations($gallery->getId(), $generation);
		return compact('indexed', 'removed', 'truncated', 'generation');
	}

	/** @return array{items: list<array<string, mixed>>, nextCursor: ?string, total: int} */
	public function page(
		Gallery $gallery,
		int $limit = 60,
		?string $cursor = null,
		string $pathPrefix = '',
		string $search = '',
		string $sortBy = 'name',
		string $sortDirection = 'asc',
		int $minOwnerRating = 0,
	): array {
		$limit = max(1, min(200, $limit));
		$pathPrefix = $this->normalizePath($pathPrefix);
		$search = mb_substr(mb_strtolower(trim($search)), 0, 120);
		if (!in_array($sortBy, ['name', 'modified', 'size'], true) || !in_array($sortDirection, ['asc', 'desc'], true)) {
			throw new \InvalidArgumentException('Invalid media index arrangement');
		}
		[$afterValue, $afterFileId] = $this->decodeCursor($cursor, $sortBy, $sortDirection, $pathPrefix, $search);
		$entries = $this->index->page(
			$gallery->getId(),
			$limit + 1,
			$afterValue,
			$afterFileId,
			$pathPrefix,
			$search,
			$sortBy,
			$sortDirection,
		);
		$hasMore = count($entries) > $limit;
		if ($hasMore) array_pop($entries);

		$items = [];
		$culls = $minOwnerRating > 0
			? $this->culling->forFiles($gallery->getOwnerUid(), array_map(static fn (MediaIndex $entry): int => $entry->getFileId(), $entries))
			: [];
		foreach ($entries as $entry) {
			if ($minOwnerRating > 0 && (($culls[$entry->getFileId()] ?? null)?->getRating() ?? 0) < $minOwnerRating) continue;
			try {
				$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $entry->getFileId());
				if (!hash_equals($entry->getEtag(), $file->getEtag())) continue;
				$items[] = $entry->jsonSerialize();
			} catch (\Throwable) {
				// A stale cache row is skipped; the next reconciliation removes it.
			}
		}
		$last = $hasMore && $entries !== [] ? $entries[array_key_last($entries)] : null;
		return [
			'items' => $items,
			'nextCursor' => $last === null ? null : $this->encodeCursor($last, $sortBy, $sortDirection, $pathPrefix, $search),
			'total' => $this->index->countFiltered($gallery->getId(), $pathPrefix, $search),
		];
	}

	/** @return array{groups: array<string, int>, indexed: int, limit: int, limitReached: bool, complete: bool, state: string, lastIndexedAt: ?int} */
	public function summary(Gallery $gallery, string $pathPrefix, string $search, string $groupBy, int $groupDepth, int $minOwnerRating = 0): array {
		$pathPrefix = $this->normalizePath($pathPrefix);
		$groups = [];
		$rows = $this->index->groupingRows($gallery->getId(), $pathPrefix, mb_strtolower(trim($search)));
		$culls = $minOwnerRating > 0 ? $this->culling->forFiles($gallery->getOwnerUid(), array_column($rows, 'file_id')) : [];
		foreach ($rows as $row) {
			if ($minOwnerRating > 0 && (($culls[$row['file_id']] ?? null)?->getRating() ?? 0) < $minOwnerRating) continue;
			$key = match ($groupBy) {
				'type' => str_starts_with($row['mime_type'], 'video/') ? 'video' : 'image',
				'folder' => $this->folderGroup($row['relative_path'], $pathPrefix, $groupDepth),
				default => 'all',
			};
			$groups[$key] = ($groups[$key] ?? 0) + 1;
		}
		ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
		$indexed = $this->index->countGallery($gallery->getId());
		$limit = $this->policies->get('maxIndexedMedia');
		$lastIndexedAt = $this->index->lastSeenAt($gallery->getId());
		$state = $indexed === 0 ? 'unindexed' : ($indexed >= $limit ? 'limit_reached' : 'ready');
		return [
			'groups' => $groups,
			'indexed' => $indexed,
			'limit' => $limit,
			'limitReached' => $indexed >= $limit,
			'complete' => $state === 'ready',
			'state' => $state,
			'lastIndexedAt' => $lastIndexedAt,
		];
	}

	private function upsert(int $galleryId, File $file, int $parentId, string $relativePath, int $depth, string $generation, int $now): void {
		$sortKey = mb_strtolower(mb_substr($relativePath, 0, 512));
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('proofing_media_index')
			->set('parent_file_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT))
			->set('relative_path', $qb->createNamedParameter($relativePath))
			->set('sort_key', $qb->createNamedParameter($sortKey))
			->set('name', $qb->createNamedParameter($file->getName()))
			->set('mime_type', $qb->createNamedParameter($file->getMimeType()))
			->set('size', $qb->createNamedParameter((int)$file->getSize(), IQueryBuilder::PARAM_INT))
			->set('mtime', $qb->createNamedParameter($file->getMTime(), IQueryBuilder::PARAM_INT))
			->set('etag', $qb->createNamedParameter($file->getEtag()))
			->set('depth', $qb->createNamedParameter($depth, IQueryBuilder::PARAM_INT))
			->set('scan_generation', $qb->createNamedParameter($generation))
			->set('seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($updated === 1) return;

		$insert = $this->db->getQueryBuilder();
		try {
			$insert->insert('proofing_media_index')->values([
				'gallery_id' => $insert->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'file_id' => $insert->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT),
				'parent_file_id' => $insert->createNamedParameter($parentId, IQueryBuilder::PARAM_INT),
				'relative_path' => $insert->createNamedParameter($relativePath),
				'sort_key' => $insert->createNamedParameter($sortKey),
				'name' => $insert->createNamedParameter($file->getName()),
				'mime_type' => $insert->createNamedParameter($file->getMimeType()),
				'size' => $insert->createNamedParameter((int)$file->getSize(), IQueryBuilder::PARAM_INT),
				'mtime' => $insert->createNamedParameter($file->getMTime(), IQueryBuilder::PARAM_INT),
				'etag' => $insert->createNamedParameter($file->getEtag()),
				'depth' => $insert->createNamedParameter($depth, IQueryBuilder::PARAM_INT),
				'scan_generation' => $insert->createNamedParameter($generation),
				'seen_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		} catch (UniqueConstraintViolationException) {
			$this->upsert($galleryId, $file, $parentId, $relativePath, $depth, $generation, $now);
		}
	}

	/** @return array{0: ?string, 1: ?int} */
	private function decodeCursor(?string $cursor, string $sortBy, string $sortDirection, string $path, string $search): array {
		if ($cursor === null || $cursor === '') return [null, null];
		$decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
		$data = $decoded === false ? null : json_decode($decoded, true);
		$value = $data['value'] ?? null;
		if (!is_array($data)
			|| (!is_string($value) && !is_int($value))
			|| !is_int($data['fileId'] ?? null)
			|| ($data['sortBy'] ?? null) !== $sortBy
			|| ($data['sortDirection'] ?? null) !== $sortDirection
			|| ($data['scope'] ?? null) !== hash('sha256', $path . "\0" . $search)) {
			throw new \InvalidArgumentException('Invalid media cursor');
		}
		return [$value, $data['fileId']];
	}

	private function encodeCursor(MediaIndex $entry, string $sortBy, string $sortDirection, string $path, string $search): string {
		$value = match ($sortBy) {
			'modified' => $entry->getMtime(),
			'size' => $entry->getSize(),
			default => $entry->getSortKey(),
		};
		return rtrim(strtr(base64_encode(json_encode([
			'value' => $value,
			'fileId' => $entry->getFileId(),
			'sortBy' => $sortBy,
			'sortDirection' => $sortDirection,
			'scope' => hash('sha256', $path . "\0" . $search),
		], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
	}

	private function folderGroup(string $relativePath, string $pathPrefix, int $groupDepth): string {
		$relative = $pathPrefix === '' ? $relativePath : substr($relativePath, strlen($pathPrefix) + 1);
		$parts = explode('/', $relative);
		array_pop($parts);
		if ($parts === []) return 'root';
		return implode('/', array_slice($parts, 0, max(1, $groupDepth)));
	}

	private function normalizePath(string $path): string {
		$path = trim($path, '/');
		if (in_array('..', explode('/', $path), true) || str_contains($path, "\0")) throw new \InvalidArgumentException('Invalid gallery path');
		return $path;
	}

	private function isSupported(File $file): bool {
		return str_starts_with($file->getMimeType(), 'image/') || in_array($file->getMimeType(), self::SUPPORTED_VIDEO_MIMES, true);
	}
}
