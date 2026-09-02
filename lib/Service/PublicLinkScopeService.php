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
		if (str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
			throw new NotFoundException('Invalid gallery path');
		}
		return $path;
	}

	public function indexPath(PublicLink $link, string $guestPath): string {
		if ($this->isMultiRoot($link)) return $this->normalize($guestPath);
		return implode('/', array_filter([
			$this->normalize($link->getStartPath()),
			$this->normalize($guestPath),
		], static fn (string $part): bool => $part !== ''));
	}

	/** @return list<string> */
	public function roots(PublicLink $link): array {
		if ($link->getScopeMode() === 'empty') return [];
		if ($link->getScopeMode() === 'nodes') return $this->nodeRoots($link)['paths'];
		$roots = $link->allowedRootList();
		if ($roots !== []) return array_values(array_unique(array_map($this->normalize(...), $roots)));
		return [$this->normalize($link->getStartPath())];
	}

	public function isMultiRoot(PublicLink $link): bool {
		return in_array($link->getScopeMode(), ['nodes', 'empty'], true) || $link->allowedRootList() !== [];
	}

	/** @return array{state: 'healthy'|'degraded'|'empty', total: int, available: int, missing: int} */
	public function health(PublicLink $link): array {
		if ($link->getScopeMode() === 'empty') return ['state' => 'empty', 'total' => 0, 'available' => 0, 'missing' => 0];
		if ($link->getScopeMode() !== 'nodes') return ['state' => 'healthy', 'total' => count($this->roots($link)), 'available' => count($this->roots($link)), 'missing' => 0];
		$resolved = $this->nodeRoots($link);
		return [
			'state' => $resolved['missing'] === 0 ? 'healthy' : 'degraded',
			'total' => $resolved['total'],
			'available' => count($resolved['paths']),
			'missing' => $resolved['missing'],
		];
	}

	/** A folder is visible when it is an allowed root, below one, or leads to one. */
	public function visiblePath(PublicLink $link, string $path): bool {
		try { $path = $this->normalize($path); } catch (NotFoundException) { return false; }
		foreach ($this->roots($link) as $root) {
			if ($path === $root || str_starts_with($path, $root . '/') || ($path !== '' && str_starts_with($root, $path . '/'))) return true;
		}
		return $path === '';
	}

	public function contains(PublicLink $link, GallerySettings $settings, string $relativeFilePath): bool {
		try {
			$relativeFilePath = $this->normalize($relativeFilePath);
		} catch (NotFoundException) {
			return false;
		}
		$matchedRoot = null;
		foreach ($this->roots($link) as $root) {
			if ($root === '' || $relativeFilePath === $root || str_starts_with($relativeFilePath, $root . '/')) {
				$matchedRoot = $root;
				break;
			}
		}
		if ($matchedRoot === null) return false;
		if ($this->isMultiRoot($link) || $link->getViewMode() === 'recursive' || $settings->navigation->folders) return true;
		return trim(dirname($relativeFilePath), './') === $matchedRoot;
	}

	/** @return array{paths: list<string>, total: int, missing: int} */
	private function nodeRoots(PublicLink $link): array {
		if ($this->rootRows === null || $this->galleries === null || $this->folders === null) {
			throw new \LogicException('Stable public link scopes are not configured');
		}
		$rows = $link->getId() === null ? [] : $this->rootRows->findForLink((int)$link->getId());
		$gallery = $this->galleries->find($link->getGalleryId());
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$paths = [];
		$missing = 0;
		$prefix = rtrim($root->getPath(), '/') . '/';
		foreach ($rows as $row) {
			$node = null;
			foreach ($root->getById($row['folderId']) as $candidate) {
				if ($candidate instanceof \OCP\Files\Folder && $root->isSubNode($candidate)) { $node = $candidate; break; }
			}
			if ($node === null) { $missing++; continue; }
			$path = str_starts_with($node->getPath(), $prefix) ? substr($node->getPath(), strlen($prefix)) : '';
			if ($path === '') { $missing++; continue; }
			$paths[] = $this->normalize($path);
		}
		return ['paths' => array_values(array_unique($paths)), 'total' => count($rows), 'missing' => $missing];
	}
}
