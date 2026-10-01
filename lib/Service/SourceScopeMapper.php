<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Dto\SourceScopeMapping;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** Compute exact relative-path mappings without changing shares or records. */
final class SourceScopeMapper {
	public function __construct(private PublicLinkScopeService $scopes) {
	}

	/** @param list<array{folderId: int, pathSnapshot: string, role: string}> $rows */
	public function map(PublicLink $link, ?Folder $oldRoot, Folder $newRoot, array $rows): SourceScopeMapping {
		if (!$this->scopes->isMultiRoot($link)) {
			$path = $this->scopes->normalize($link->getStartPath());
			if ($path !== '' && $rows !== []) $path = $this->currentPath($oldRoot, $rows[0]);
			$target = $this->folderAt($newRoot, $path);
			return new SourceScopeMapping(false, $target, $target === null ? [] : [['folder' => $target, 'path' => $path, 'role' => 'shared']], $target === null ? [$path] : []);
		}
		if ($rows === []) {
			$paths = $link->allowedRootList();
			if ($paths === [] && $link->getStartPath() !== '') $paths = [$link->getStartPath()];
			$rows = array_map(static fn (string $path): array => ['folderId' => 0, 'pathSnapshot' => $path, 'role' => 'shared'], $paths);
		}
		$roots = []; $missing = []; $seen = [];
		foreach ($rows as $row) {
			$path = $this->currentPath($oldRoot, $row);
			if (isset($seen[$path])) continue;
			$seen[$path] = true;
			// An empty multi-root path must never turn into the entire source.
			if ($path === '') throw new \InvalidArgumentException('A restricted source scope cannot include the gallery root');
			$target = $this->folderAt($newRoot, $path);
			if ($target === null) { $missing[] = $path; continue; }
			$roots[] = ['folder' => $target, 'path' => $path, 'role' => $row['role']];
		}
		return new SourceScopeMapping(true, null, $roots, $missing);
	}

	/** @param array{folderId: int, pathSnapshot: string, role: string} $row */
	private function currentPath(?Folder $oldRoot, array $row): string {
		if ($oldRoot !== null && $row['folderId'] > 0) {
			$prefix = rtrim($oldRoot->getPath(), '/') . '/';
			try {
				foreach ($oldRoot->getById($row['folderId']) as $node) {
					if ($node instanceof Folder && $oldRoot->isSubNode($node) && str_starts_with($node->getPath(), $prefix)) {
						return $this->scopes->normalize(substr($node->getPath(), strlen($prefix)));
					}
				}
			} catch (NotFoundException) {
				// A removed old folder can still be mapped from its stored path.
			}
		}
		return $this->scopes->normalize($row['pathSnapshot']);
	}

	private function folderAt(Folder $root, string $path): ?Folder {
		try {
			$node = $path === '' ? $root : $root->get($path);
			return $node instanceof Folder && $node->isReadable() && ($path === '' || $root->isSubNode($node)) ? $node : null;
		} catch (NotFoundException) {
			return null;
		}
	}
}
