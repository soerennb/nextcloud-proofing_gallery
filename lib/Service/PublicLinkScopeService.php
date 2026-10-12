<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\PublicLinkRootRepository;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\Files\NotFoundException;

final class PublicLinkScopeService {
	public function __construct(
		private ?PublicLinkRootRepository $rootRows = null,
		private ?GalleryMapper $galleries = null,
		private ?FolderService $folders = null,
	) {
	}

	public function normalize(string $path): string {
		$path = trim($path, '/');
		if (!MediaTypePolicy::visiblePath($path)) {
			throw new NotFoundException('Invalid gallery path');
		}
		return $path;
	}

	public function resolve(PublicLink $link): \OCA\ProofingGallery\Dto\PublicLinkScope {
		$multi = $this->isMultiRoot($link);
		$start = $this->normalize($link->getStartPath());
		if ($link->getScopeMode() === 'nodes') {
			$resolved = $this->nodeRoots($link);
			$roots = $resolved['paths']; $total = $resolved['total']; $missing = $resolved['missing'];
			$details = array_map(static fn (array $entry): array => ['path' => $entry['path'], 'name' => basename($entry['path']), 'role' => in_array($entry['role'], ['shared', 'group', 'private'], true) ? $entry['role'] : 'shared'], $resolved['entries']);
		} else {
			$roots = $link->getScopeMode() === 'empty' ? [] : ($link->allowedRootList() ?: [$start]);
			$roots = array_values(array_unique(array_map($this->normalize(...), $roots)));
			$total = count($roots); $missing = 0;
			$details = array_map(static fn (string $path): array => ['path' => $path, 'name' => basename($path), 'role' => 'shared'], $roots);
		}
		return new \OCA\ProofingGallery\Dto\PublicLinkScope($multi, $start, $roots, $details, ['state' => $missing > 0 ? 'degraded' : ($roots === [] ? 'empty' : 'healthy'), 'total' => $total, 'available' => count($roots), 'missing' => $missing]);
	}

	public function indexPath(PublicLink $link, string $guestPath): string { return $this->resolve($link)->indexPath($guestPath); }
	/** @return list<string> */
	public function roots(PublicLink $link): array { return $this->resolve($link)->roots; }
	/** @return list<array{path: string, name: string, role: string}> */
	public function rootDetails(PublicLink $link): array { return $this->resolve($link)->details; }
	public function isMultiRoot(PublicLink $link): bool { return in_array($link->getScopeMode(), ['nodes', 'empty'], true) || $link->allowedRootList() !== []; }
	/** @return array{state:string, total:int, available:int, missing:int} */
	public function health(PublicLink $link): array { return $this->resolve($link)->health; }
	public function visiblePath(PublicLink $link, string $path): bool { return $this->resolve($link)->visiblePath($path); }
	public function contains(PublicLink $link, GallerySettings $settings, string $relativeFilePath): bool { return $this->resolve($link)->contains($relativeFilePath, $link->getViewMode() === 'recursive' || $settings->navigation->folders); }

	/** @return array{paths: list<string>, entries: list<array{path: string, role: string}>, total: int, missing: int} */
	private function nodeRoots(PublicLink $link): array {
		if ($this->rootRows === null || $this->galleries === null || $this->folders === null) {
			throw new \LogicException('Stable public link scopes are not configured');
		}
		$rows = $link->getId() === null ? [] : $this->rootRows->findForLink((int)$link->getId());
		$gallery = $this->galleries->find($link->getGalleryId());
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$paths = [];
		$entries = [];
		$missing = 0;
		$prefix = rtrim($root->getPath(), '/') . '/';
		foreach ($rows as $row) {
			$node = null;
			foreach ($root->getById($row['folderId']) as $candidate) {
				if ($candidate instanceof \OCP\Files\Folder && $candidate->isReadable() && $root->isSubNode($candidate)) { $node = $candidate; break; }
			}
			if ($node === null) { $missing++; continue; }
			$path = str_starts_with($node->getPath(), $prefix) ? substr($node->getPath(), strlen($prefix)) : '';
			if ($path === '') { $missing++; continue; }
			try { $path = $this->normalize($path); } catch (NotFoundException) { $missing++; continue; }
			if (in_array($path, $paths, true)) continue;
			$paths[] = $path;
			$entries[] = ['path' => $path, 'role' => (string)$row['role']];
		}
		return ['paths' => $paths, 'entries' => $entries, 'total' => count($rows), 'missing' => $missing];
	}
}
