<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
use OCA\ProofingGallery\Dto\PublicGalleryPage;
use OCA\ProofingGallery\Domain\MediaSort;
use OCA\ProofingGallery\Domain\PublicPagePagination;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\ICacheFactory;

final class CollectionPublicPageReader {
	/** Safety bound only: the source fingerprint invalidates listings on every relevant change. */
	private const LISTING_TTL = 3600;
	private readonly CompressedListCache $listings;

	public function __construct(private CollectionService $collections, private MediaMetadataService $metadata, ICacheFactory $cacheFactory) {
		$this->listings = new CompressedListCache($cacheFactory->createDistributed('proofing_gallery_collection_listing'));
	}

	public function read(PublicShareContext $context, PublicGalleryQuery $query): PublicGalleryPage {
		$gallery = $context->gallery; $settings = $context->settings;
		$limit = $query->limit; $offset = $query->offset; $search = $query->search;
		$sortBy = $query->sortBy; $sortDirection = $query->sortDirection; $groupBy = $query->groupBy;
		$focusId = $query->focusId;
		$matches = static fn (array $item): bool => $search === '' || mb_stripos((string)$item['name'], $search) !== false;

		// Capture-date sorting needs each file's metadata, so it reads the resolved files once.
		// Every other sort works on the cached listing and resolves only the visible page.
		$filesById = [];
		if ($sortBy === 'capturedAt') {
			$entries = array_values(array_filter(
				iterator_to_array($this->collections->availableFileStream($gallery), false),
				static fn (array $entry): bool => $matches($entry['item']),
			));
			foreach ($entries as $entry) $filesById[$entry['item']['id']] = $entry['file'];
			$nodes = array_map(static fn (array $entry): array => $entry['item'], $entries);
			$captureById = [];
			foreach ($filesById as $id => $file) {
				$captureById[$id] = $this->metadata->summary($file)['capturedAt'] ?? null;
			}
		} else {
			$nodes = array_values(array_filter($this->listing($gallery), $matches));
			$captureById = [];
		}
		if ($sortBy !== 'collection') usort($nodes, static fn (array $left, array $right): int => MediaSort::compare(
			[...$left, 'capturedAt' => $captureById[$left['id']] ?? null], [...$right, 'capturedAt' => $captureById[$right['id']] ?? null], $sortBy, $sortDirection,
		));
		$focusIndex = $focusId === null ? null : PublicPagePagination::itemPosition($nodes, $focusId);
		if ($focusId !== null && $focusIndex === null) throw new NotFoundException('Gallery media not found');
		$offset = PublicPagePagination::offset(count($nodes), $limit, $offset, $focusIndex);
		$pageItems = array_map(function (array $item) use ($gallery, $filesById, $settings): array {
			try {
				$file = $filesById[$item['id']] ?? $this->collections->resolveMedia($gallery, (int)$item['id']);
				$public = $this->metadata->publicItem($file, $settings->metadata->publicFields);
				$item['metadata'] = $public['metadata'];
				return [...$item, ...$public['geometry']];
			} catch (NotFoundException|FolderAccessException|DoesNotExistException) {
				// A file removed after the listing was cached stays unavailable instead of failing the page.
				$item['metadata'] = ['state' => 'unavailable'];
				return $item;
			}
		}, array_slice($nodes, $offset, $limit));
		return new PublicGalleryPage($pageItems, count($nodes), $query, $offset,
		nextCursor: null, previousCursor: null, groups: [],
		indexState: ['indexed' => count($nodes), 'limit' => count($nodes), 'limitReached' => false, 'complete' => true], scope: ['startPath' => '', 'viewMode' => 'collection', 'groupDepth' => 1], focusIndex: $focusIndex,
	);

	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function listing(Gallery $gallery): array {
		$fingerprint = $this->collections->listingFingerprint($gallery);
		if ($fingerprint === null) return $this->collections->availableItems($gallery);
		$cached = $this->listings->get($fingerprint);
		if (is_array($cached)) return $cached;
		$items = $this->collections->availableItems($gallery);
		$this->listings->set($fingerprint, $items, self::LISTING_TTL);
		return $items;
	}
}
