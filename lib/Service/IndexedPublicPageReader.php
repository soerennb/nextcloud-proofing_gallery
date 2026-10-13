<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
use OCA\ProofingGallery\Dto\PublicGalleryPage;
use OCA\ProofingGallery\Domain\MediaSort;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

final class IndexedPublicPageReader {
	public function __construct(private MediaIndexService $mediaIndex, private PublicMediaResolver $publicMedia, private MediaMetadataService $metadata) {}

	public function read(PublicShareContext $context, PublicGalleryQuery $query): PublicGalleryPage {
		$gallery = $context->gallery; $settings = $context->settings;
		$limit = $query->limit; $offset = $query->offset; $path = $query->path; $search = $query->search;
		$sortBy = $query->sortBy; $sortDirection = $query->sortDirection; $groupBy = $query->groupBy;
		$focusId = $query->focusId;
		$link = $context->link; $cursor = $query->cursor; $multiRoot = $context->scope->multiRoot;
		$startPath = $context->scope->startPath; $scopedRoot = $context->root;
		$groupDepth = max(1, min(8, $link->getGroupDepth() ?: $settings->navigation->groupDepth));

		$relativePath = $path;
		$indexPath = $context->scope->indexPath($relativePath);
		$minOwnerRating = $link->getMinOwnerRating();
		$focusIndex = null;
		if ($focusId !== null) {
			$this->publicMedia->resolve($context, $focusId);
			$focusIndex = $this->mediaIndex->positionOf($gallery, $focusId, $indexPath, $search, $sortBy, $sortDirection, $minOwnerRating);
			if ($focusIndex === null) throw new \OCP\Files\NotFoundException('Gallery media not found');
			$offset = \OCA\ProofingGallery\Domain\PublicPagePagination::offset(PHP_INT_MAX, $limit, $offset, $focusIndex);
		}
		$page = $this->mediaIndex->page($gallery, $limit, $cursor, $indexPath, $search, $sortBy, $sortDirection, $minOwnerRating, $offset);
		$items = [];
		foreach ($page['items'] as $item) {
			try {
				$file = $this->publicMedia->resolve($context, (int)$item['id']);
				$item['folder'] = false;
				$item['group'] = $this->indexedGroup((string)$item['relativePath'], (string)$item['mimeType'], $indexPath, $groupBy, $groupDepth);
				$item['metadata'] = $this->metadata->publicSummary($file, $settings->metadata->publicFields);
				$item = [...$item, ...$this->metadata->publicGeometry($file)];
				$items[] = $item;
			} catch (\Throwable) {
				// Stale index entries never become public through a missing node.
			}
		}
		$summary = $this->mediaIndex->summary($gallery, $indexPath, $search, $groupBy, $groupDepth, $minOwnerRating);
		$page['total'] = array_sum($summary['groups']);
		return new PublicGalleryPage($items, $page['total'], $query, $offset,
		nextCursor: $page['nextCursor'], previousCursor: $page['previousCursor'], groups: $summary['groups'],
		indexState: array_diff_key($summary, ['groups' => true]), scope: ['startPath' => $startPath, 'viewMode' => 'recursive', 'groupDepth' => $groupDepth], focusIndex: $focusIndex,
	);

	}

	private function indexedGroup(string $relativePath, string $mimeType, string $pathPrefix, string $groupBy, int $groupDepth): string {
		if ($groupBy === 'none') return 'all';
		if ($groupBy === 'type') return str_starts_with($mimeType, 'video/') ? 'video' : 'image';
		$relative = $pathPrefix === '' ? $relativePath : substr($relativePath, strlen($pathPrefix) + 1);
		$parts = explode('/', $relative);
		array_pop($parts);
		return $parts === [] ? 'root' : implode('/', array_slice($parts, 0, $groupDepth));
	}
}
