<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Db\KioskRepository;

final class PublicGalleryPresenter {
	public function __construct(private FeedbackPolicyService $feedback, private CapabilityPolicyService $capabilities, private GalleryArtworkService $artwork, private PublicMediaResolver $publicMedia, private MediaMetadataService $metadata, private VideoTranscodeService $videoTranscodes, private KioskRepository $kiosks) {}
	/** @return array<string, mixed> */
	public function present(PublicShareContext $context, \OCA\ProofingGallery\Dto\PublicGalleryPage $page): array {
		$items = $page->items; $total = $page->total; $offset = $page->offset;
		$query = $page->query;
		$limit = $query->limit; $path = $query->path; $search = $query->search;
		$sortBy = $query->sortBy; $sortDirection = $query->sortDirection; $groupBy = $query->groupBy;
		$nextCursor = $page->nextCursor; $previousCursor = $page->previousCursor;
		$groups = $page->groups; $indexState = $page->indexState; $scope = $page->scope; $focusIndex = $page->focusIndex;
		$gallery = $context->gallery;
		$settings = $this->feedback->publicSettings($context->settings, $context->policy);
		$effective = $this->capabilities->effective($settings);
		$serialized = $settings->jsonSerialize();
		$serialized['presentation']['heroFileId'] = $this->artwork->publicHeroFileId($context);
		$serialized['presentation']['coverFileId'] = null;
		foreach ($serialized['presentation']['story']['sections'] ?? [] as &$section) {
			$section['mediaIds'] = array_values(array_filter(
				$section['mediaIds'] ?? [],
				fn (mixed $fileId): bool => is_int($fileId) && $this->publicMedia->allows($context, $fileId),
			));
		}
		unset($section);
		$storyItems = $this->storyItems($context, $serialized, $items);
		if (!$effective['downloads']['allowed']) {
			$serialized['delivery']['downloadScope'] = 'none';
		}
		if (!$effective['guestUploads']['allowed']) {
			$serialized['delivery']['guestUploads'] = false;
		}
		if ($context->link->getPublicLocale() !== null) $serialized['publicLocale'] = $context->link->getPublicLocale();
		$this->addPlayback($context, $items);
		$this->addPlayback($context, $storyItems);
		return [
			'gallery' => [
				'id' => $gallery->getId(),
				'title' => $gallery->getTitle(),
				'deliveryMode' => $gallery->getDeliveryMode(),
				'liveUpdates' => $this->kiosks->forGallery((int)$gallery->getId()) !== null,
				'settings' => $serialized,
				'effectiveCapabilities' => $effective,
			],
			'items' => $items,
			's' => $storyItems,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'page' => intdiv($offset, $limit) + 1,
			'pageSize' => $limit,
			'pageCount' => max(1, (int)ceil($total / $limit)),
			'focusIndex' => $focusIndex,
			'previousCursor' => $previousCursor,
			'nextCursor' => $nextCursor,
			'path' => $path,
			'groups' => $groups,
			'indexState' => $indexState,
			'scope' => $scope,
			'view' => compact('search', 'sortBy', 'sortDirection', 'groupBy'),
		];
	}

	/** @param array<string, mixed> $settings
	 * @param list<array<string, mixed>> $pageItems
	 * @return list<array<string, mixed>>
	 */
	private function storyItems(PublicShareContext $context, array $settings, array $pageItems): array {
		$pageIds = array_fill_keys(array_map(static fn (array $item): int => (int)$item['id'], $pageItems), true);
		$storyIds = array_values(array_unique(array_merge(...array_map(
			static fn (array $section): array => $section['mediaIds'] ?? [],
			$settings['presentation']['story']['sections'] ?? [],
		))));
		$items = [];
		foreach ($storyIds as $fileId) {
			if (isset($pageIds[$fileId])) continue;
			try {
				$file = $this->publicMedia->resolve($context, $fileId);
				$items[] = [
					'id' => $fileId, 'name' => $file->getName(), 'mimeType' => $file->getMimeType(),
					'size' => (int)$file->getSize(), 'modifiedAt' => $file->getMTime(), 'etag' => $file->getEtag(), 'folder' => false,
					'metadata' => $this->metadata->publicSummary($file, $settings['metadata']['publicFields']),
					...$this->metadata->publicGeometry($file),
				];
			} catch (\Throwable) {
				// Removed, unreadable or out-of-scope story media stays private.
			}
		}
		return $items;
	}

	/** @param list<array<string, mixed>> $items */
	private function addPlayback(PublicShareContext $context, array &$items): void {
		foreach ($items as &$item) {
			if (!str_starts_with((string)($item['mimeType'] ?? ''), 'video/')) continue;
			try {
				$item['playback'] = $this->videoTranscodes->request(
					$context->gallery->getOwnerUid(),
					$this->publicMedia->resolve($context, (int)$item['id']),
				);
			} catch (\Throwable) {
				$item['playback'] = ['state' => 'unavailable', 'playable' => false];
			}
		}
		unset($item);
	}

}
