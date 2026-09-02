<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\PublicShareContext;
use OCP\Files\File;
use OCP\Files\Folder;

final class PublicGalleryDownloadService {
	public function __construct(
		private CollectionService $collections,
		private MediaIndexService $mediaIndex,
		private MediaTypePolicy $mediaTypes,
		private PolicyService $policies,
		private PublicLinkScopeService $linkScopes,
		private PublicMediaResolver $publicMedia,
	) {
	}

	/**
	 * @return array{
	 *   available: bool,
	 *   reason: ?string,
	 *   fileCount: int,
	 *   totalBytes: int,
	 *   maxFiles: int,
	 *   maxBytes: int,
	 *   entries: list<array{file: File, path: string}>
	 * }
	 */
	public function inspect(PublicShareContext $context): array {
		$entries = $this->entries($context);
		if ($entries === null) return $this->result([], 'index_incomplete');
		$totalBytes = array_sum(array_map(static fn (array $entry): int => (int)$entry['file']->getSize(), $entries));
		$reason = $entries === [] ? 'empty'
			: (count($entries) > $this->policies->get('maxGalleryDownloadFiles') ? 'too_many_files'
				: ($totalBytes > $this->policies->get('maxSelectionBytes') ? 'too_large' : null));
		return $this->result($entries, $reason, $totalBytes);
	}

	/** @return ?list<array{file: File, path: string}> */
	private function entries(PublicShareContext $context): ?array {
		if ($context->gallery->getSourceType() === 'collection') {
			$entries = [];
			foreach ($this->collections->availableItems($context->gallery) as $item) {
				$file = $this->publicMedia->resolve($context, (int)$item['id']);
				$entries[] = ['file' => $file, 'path' => $this->collections->downloadPath($context->gallery, $file)];
			}
			return $this->uniquePaths($entries);
		}
		if ($context->link->getViewMode() === 'recursive') {
			$prefix = $this->linkScopes->indexPath($context->link, '');
			$rows = $this->mediaIndex->downloadableRows($context->gallery, $prefix, $context->link->getMinOwnerRating());
			if ($rows === null) return null;
			$entries = [];
			foreach ($rows as $row) {
				try {
					$file = $this->publicMedia->resolve($context, $row['file_id']);
					$path = $prefix !== '' ? substr($row['relative_path'], strlen($prefix) + 1) : $row['relative_path'];
					$entries[] = ['file' => $file, 'path' => $path];
				} catch (\Throwable) {
					// Stale index entries are never exported.
				}
			}
			return $this->uniquePaths($entries);
		}
		$recursive = $context->settings->navigation->folders;
		if ($this->linkScopes->isMultiRoot($context->link)) {
			$entries = [];
			foreach ($this->linkScopes->roots($context->link) as $rootPath) {
				$node = $context->root->get($rootPath);
				if (!$node instanceof Folder || !$context->root->isSubNode($node)) continue;
				array_push($entries, ...$this->folderEntries($node, $rootPath, true));
			}
			return $this->uniquePaths($entries);
		}
		return $this->uniquePaths($this->folderEntries($context->root, '', $recursive));
	}

	/** @return list<array{file: File, path: string}> */
	private function folderEntries(Folder $folder, string $prefix, bool $recursive): array {
		$entries = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (str_starts_with($node->getName(), '.')) continue;
			$path = ltrim($prefix . '/' . $node->getName(), '/');
			if ($node instanceof File && $this->mediaTypes->supports($node)) {
				$entries[] = ['file' => $node, 'path' => $path];
			} elseif ($recursive && $node instanceof Folder) {
				array_push($entries, ...$this->folderEntries($node, $path, true));
			}
		}
		return $entries;
	}

	/** @param list<array{file: File, path: string}> $entries
	 * @return list<array{file: File, path: string}>
	 */
	private function uniquePaths(array $entries): array {
		$used = [];
		foreach ($entries as &$entry) {
			$path = str_replace("\0", '', trim($entry['path'], '/')) ?: $entry['file']->getName();
			$candidate = $path;
			$counter = 2;
			while (isset($used[mb_strtolower($candidate)])) {
				$extension = pathinfo($path, PATHINFO_EXTENSION);
				$stem = $extension === '' ? $path : substr($path, 0, -strlen($extension) - 1);
				$candidate = $stem . '-' . $counter++ . ($extension === '' ? '' : '.' . $extension);
			}
			$entry['path'] = $candidate;
			$used[mb_strtolower($candidate)] = true;
		}
		unset($entry);
		return $entries;
	}

	/** @param list<array{file: File, path: string}> $entries
	 * @return array{available: bool, reason: ?string, fileCount: int, totalBytes: int, maxFiles: int, maxBytes: int, entries: list<array{file: File, path: string}>}
	 */
	private function result(array $entries, ?string $reason, ?int $totalBytes = null): array {
		return [
			'available' => $reason === null,
			'reason' => $reason,
			'fileCount' => count($entries),
			'totalBytes' => $totalBytes ?? 0,
			'maxFiles' => $this->policies->get('maxGalleryDownloadFiles'),
			'maxBytes' => $this->policies->get('maxSelectionBytes'),
			'entries' => $entries,
		];
	}
}
