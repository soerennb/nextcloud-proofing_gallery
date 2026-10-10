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
	public function __construct(private MediaIndexScanRepository $cache, private MediaTypePolicy $types, private IMountManager $mounts) {
	}

	/** @return array{items: list<Node>, after: int, examined: int, done: bool} */
	public function page(Folder $root, string $path, int $after, int $limit): array {
		if (preg_match('~(^|/)\.~', $path)) return ['items' => [], 'after' => $after, 'examined' => 0, 'done' => true];
		$node = $path === '' ? $root : $root->get($path);
		if (!$node->isReadable() || ($node !== $root && !$root->isSubNode($node))) return ['items' => [], 'after' => $after, 'examined' => 0, 'done' => true];
		if ($node instanceof File) return ['items' => $this->types->supports($node) && $after === 0 ? [$node] : [], 'after' => (int)$node->getId(), 'examined' => 1, 'done' => true];
		if (!$node instanceof Folder) return ['items' => [], 'after' => $after, 'examined' => 0, 'done' => true];
		$rows = $this->cache->children((int)$node->getStorage()->getCache()->getNumericStorageId(), (int)$node->getId(), $after, $limit);
		$items = [];
		foreach ($rows as $row) {
			$after = $row['file_id'];
			if (str_starts_with($row['name'], '.')) continue;
			try {
				$child = $node->get($row['name']);
				if ($child->isReadable() && ($child instanceof Folder || ($child instanceof File && $this->types->supports($child)))) $items[] = $child;
			} catch (NotFoundException|NotPermittedException) {
				// Deletion races are repaired by the next source generation.
			}
		}
		return ['items' => $items, 'after' => $after, 'examined' => count($rows), 'done' => count($rows) < $limit];
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
		return $file->isReadable() && $root->isSubNode($file) && $this->types->supports($file)
			&& !preg_match('~(^|/)\.~', $this->relativePath($root, $file));
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
