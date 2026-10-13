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
		private PublicMediaResolver $publicMedia,
		private FolderMediaReader $reader,
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
		$stream = $this->entries($context);
		if ($stream === null) return $this->result([], 'index_incomplete');
		$entries = []; $totalBytes = 0; $reason = null;
		try {
			foreach ($stream as $entry) {
				$entries[] = $entry;
				$totalBytes += (int)$entry['file']->getSize();
				if (count($entries) > $this->policies->get('maxGalleryDownloadFiles')) { $reason = 'too_many_files'; break; }
				if ($totalBytes > $this->policies->get('maxSelectionBytes')) { $reason = 'too_large'; break; }
			}
		} catch (\OCA\ProofingGallery\Exception\DownloadScanLimitException) { $reason = 'index_incomplete'; }
		return $this->result($this->uniquePaths($entries), $reason ?? ($entries === [] ? 'empty' : null), $totalBytes);
	}

	/** @return ?iterable<array{file: File, path: string}> */
	private function entries(PublicShareContext $context): ?iterable {
		if ($context->gallery->getSourceType() === 'collection') return $this->collectionEntries($context);
		if ($context->link->getViewMode() === 'recursive') {
			$prefix = $context->scope->indexPath('');
			$rows = $this->mediaIndex->downloadableRows($context->gallery, $prefix, $context->link->getMinOwnerRating());
			return $rows === null ? null : $this->indexedEntries($context, $prefix, $rows);
		}
		return $this->folderEntries($context);
	}

	/** @return \Generator<int, array{file: File, path: string}> */
	private function collectionEntries(PublicShareContext $context): \Generator {
		foreach ($this->collections->availableItemStream($context->gallery) as $item) {
			$file = $this->publicMedia->resolve($context, (int)$item['id']);
			yield ['file' => $file, 'path' => $this->collections->downloadPath($context->gallery, $file)];
		}
	}

	/** @param iterable<array{file_id: int, relative_path: string, mime_type: string}> $rows
	 * @return \Generator<int, array{file: File, path: string}> */
	private function indexedEntries(PublicShareContext $context, string $prefix, iterable $rows): \Generator {
		foreach ($rows as $row) {
			try { $file = $this->publicMedia->resolve($context, $row['file_id']); }
			catch (\OCP\Files\NotFoundException) { continue; }
			yield ['file' => $file, 'path' => $prefix !== '' ? substr($row['relative_path'], strlen($prefix) + 1) : $row['relative_path']];
		}
	}

	/** Native traversal has a work budget as well as the export's byte/file limits.
	 * @return \Generator<int, array{file: File, path: string}> */
	private function folderEntries(PublicShareContext $context): \Generator {
		$multiRoot = $context->scope->multiRoot;
		$pending = $multiRoot ? $context->scope->roots : [''];
		$recursive = $multiRoot || $context->settings->navigation->folders;
		$budget = max(1000, $this->policies->get('maxGalleryDownloadFiles') * 10);
		while ($pending !== []) {
			$path = array_pop($pending); $after = 0;
			do {
				if ($budget <= 0) throw new \OCA\ProofingGallery\Exception\DownloadScanLimitException();
				$page = $this->reader->page($context->root, $path, $after, min(500, $budget));
				$budget -= max(1, $page['examined']); $after = $page['after'];
				foreach ($page['items'] as $node) {
					$relative = ltrim($path . '/' . $node->getName(), '/');
					if ($node instanceof Folder && $recursive) $pending[] = $relative;
					elseif ($node instanceof File && $this->mediaTypes->visibleFile($context->root, $node)) yield ['file' => $node, 'path' => $relative];
				}
			} while (!$page['done']);
		}
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
