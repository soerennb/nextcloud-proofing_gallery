<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\MediaIndexScanRepository;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/** Bounded cache pages resolved through the owner's actual file tree. */
final class FolderMediaReader {
	/** Folders up to this size are resolved from one listing (about 2 KB per child); larger ones per child. */
	public const LISTING_LIMIT = 5000;

	/** @var array{key: string, nodes: array<string, Node>}|null */
	private ?array $listing = null;

	public function __construct(private MediaIndexScanRepository $cache, private MediaTypePolicy $types, private IMountManager $mounts, private int $listingLimit = self::LISTING_LIMIT) {
	}

	/** @return array{items: list<Node>, after: int, examined: int, done: bool} */
	public function page(Folder $root, string $path, int $after, int $limit): array {
		if (!MediaTypePolicy::visiblePath($path)) return ['items' => [], 'after' => $after, 'examined' => 0, 'done' => true];
		$node = $path === '' ? $root : $root->get($path);
		if (!$node->isReadable() || ($node !== $root && !$root->isSubNode($node))) return ['items' => [], 'after' => $after, 'examined' => 0, 'done' => true];
		if ($node instanceof File) return ['items' => $this->types->supports($node) && $after === 0 ? [$node] : [], 'after' => (int)$node->getId(), 'examined' => 1, 'done' => true];
		if (!$node instanceof Folder) return ['items' => [], 'after' => $after, 'examined' => 0, 'done' => true];
		$storageId = (int)$node->getStorage()->getCache()->getNumericStorageId();
		$rows = $this->cache->children($storageId, (int)$node->getId(), $after, $limit);
		$done = count($rows) < $limit;
		$listed = $this->listed($node, $storageId, $rows, $after === 0 && $done && count($rows) <= $this->listingLimit);
		$items = [];
		foreach ($rows as $row) {
			$after = $row['file_id'];
			if (str_starts_with($row['name'], '.')) continue;
			try {
				// A name missing from the listing was added after it was taken; resolve it directly.
				$child = $listed[$row['name']] ?? $node->get($row['name']);
				if ($child->isReadable() && ($child instanceof Folder || ($child instanceof File && $this->types->supports($child)))) $items[] = $child;
			} catch (NotFoundException|NotPermittedException) {
				// Deletion races are repaired by the next source generation.
			}
		}
		if ($done) $this->listing = null;
		return ['items' => $items, 'after' => $after, 'examined' => count($rows), 'done' => $done];
	}

	/**
	 * One listing replaces a filesystem lookup per child. It is kept for the following pages of the same
	 * folder state and skipped for folders too large to hold in memory.
	 *
	 * @param list<array{name: string}> $rows
	 * @return array<string, Node>
	 */
	private function listed(Folder $folder, int $storageId, array $rows, bool $whole): array {
		if (count($rows) < 2) return [];
		$key = $storageId . ':' . $folder->getId() . ':' . $folder->getEtag();
		if ($this->listing !== null && $this->listing['key'] === $key) return $this->listing['nodes'];
		$this->listing = null;
		if (!$whole && $this->cache->hasMoreChildrenThan($storageId, (int)$folder->getId(), $this->listingLimit)) return [];
		$nodes = [];
		foreach ($folder->getDirectoryListing() as $child) $nodes[$child->getName()] = $child;
		$this->listing = ['key' => $key, 'nodes' => $nodes];
		return $nodes;
	}

	/** @return list<string> */
	public function mountPaths(Folder $root): array {
		$prefix = rtrim($root->getPath(), '/') . '/';
		$paths = [];
		foreach ($this->mounts->findIn($prefix) as $mount) {
			$path = trim(substr($mount->getMountPoint(), strlen($prefix)), '/');
			if ($path !== '' && !preg_match('~(^|/)\.~', $path)) $paths[] = $path;
		}
		return $paths;
	}

	public function relativePath(Folder $root, Node $node): string {
		return ltrim(substr($node->getPath(), strlen(rtrim($root->getPath(), '/'))), '/');
	}

	public function isVisibleMedia(Folder $root, File $file): bool {
		return $this->types->visibleFile($root, $file);
	}

	/** Readiness checks stop at the first file and never enumerate an entire directory. */
	public function hasMedia(Folder $root, int $budget = 500): bool {
		$queue = [['', 0], ...array_map(static fn (string $path): array => [$path, 0], $this->mountPaths($root))];
		$seen = [];
		while ($queue !== [] && $budget > 1) {
			[$path, $after] = array_shift($queue);
			$budget--;
			try { $page = $this->page($root, $path, $after, $budget); }
			catch (NotFoundException|NotPermittedException) { continue; }
			$budget -= $page['examined'];
			foreach ($page['items'] as $node) {
				if ($node instanceof File) return true;
				if (!isset($seen[$node->getId()])) {
					$seen[$node->getId()] = true;
					$queue[] = [$this->relativePath($root, $node), 0];
				}
			}
			if (!$page['done']) array_unshift($queue, [$path, $page['after']]);
		}
		return false;
	}
}
