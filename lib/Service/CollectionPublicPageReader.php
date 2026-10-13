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

final class CollectionPublicPageReader {
	public function __construct(private CollectionService $collections, private MediaMetadataService $metadata) {}

	public function read(PublicShareContext $context, PublicGalleryQuery $query): PublicGalleryPage {
		$gallery = $context->gallery; $settings = $context->settings;
		$limit = $query->limit; $offset = $query->offset; $path = $query->path; $search = $query->search;
		$sortBy = $query->sortBy; $sortDirection = $query->sortDirection; $groupBy = $query->groupBy;
		$focusId = $query->focusId;

		$nodes = array_values(array_filter(
			$this->collections->availableItems($gallery),
			static fn (array $item): bool => $search === '' || mb_stripos((string)$item['name'], $search) !== false,
		));
		$captureById = [];
		if ($sortBy === 'capturedAt') foreach ($nodes as $item) {
			try { $captureById[$item['id']] = $this->metadata->summary($this->collections->resolveMedia($gallery, (int)$item['id']))['capturedAt'] ?? null; }
			catch (\OCP\Files\NotFoundException|\OCA\ProofingGallery\Exception\FolderAccessException|\OCP\AppFramework\Db\DoesNotExistException) { $captureById[$item['id']] = null; }
		}
		if ($sortBy !== 'collection') usort($nodes, static fn (array $left, array $right): int => MediaSort::compare(
			[...$left, 'capturedAt' => $captureById[$left['id']] ?? null], [...$right, 'capturedAt' => $captureById[$right['id']] ?? null], $sortBy, $sortDirection,
		));
		$focusIndex = $focusId === null ? null : \OCA\ProofingGallery\Domain\PublicPagePagination::itemPosition($nodes, $focusId);
		if ($focusId !== null && $focusIndex === null) throw new \OCP\Files\NotFoundException('Gallery media not found');
		$offset = \OCA\ProofingGallery\Domain\PublicPagePagination::offset(count($nodes), $limit, $offset, $focusIndex);
		$pageItems = array_map(function (array $item) use ($gallery, $settings): array {
			try {
				$file = $this->collections->resolveMedia($gallery, (int)$item['id']);
				$item['metadata'] = $this->metadata->publicSummary($file, $settings->metadata->publicFields);
				return [...$item, ...$this->metadata->publicGeometry($file)];
			} catch (\OCP\Files\NotFoundException|\OCA\ProofingGallery\Exception\FolderAccessException|\OCP\AppFramework\Db\DoesNotExistException) {
				$item['metadata'] = ['state' => 'unavailable'];
				return $item;
			}
		}, array_slice($nodes, $offset, $limit));
		return new PublicGalleryPage($pageItems, count($nodes), $query, $offset,
		nextCursor: null, previousCursor: null, groups: [],
		indexState: ['indexed' => count($nodes), 'limit' => count($nodes), 'limitReached' => false, 'complete' => true], scope: ['startPath' => '', 'viewMode' => 'collection', 'groupDepth' => 1], focusIndex: $focusIndex,
	);

	}


}
