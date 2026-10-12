<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Service\CacheAncestorResolver;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\FileCacheUpdated;
use OCP\Files\Events\NodeAddedToCache;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

/** @implements IEventListener<FileCacheUpdated|NodeAddedToCache|NodeRemovedFromCache|BeforeNodeDeletedEvent|NodeCreatedEvent|NodeWrittenEvent|BeforeNodeRenamedEvent|NodeRenamedEvent> */
final class MediaIndexCacheListener implements IEventListener {
	public function __construct(
		private GalleryMapper $galleries,
		private CacheAncestorResolver $ancestors,
		private IJobList $jobs,
		private \OCA\ProofingGallery\Db\MediaSortRepository $sorts,
		private \OCA\ProofingGallery\Service\MediaMetadataService $metadata,
		private \OCA\ProofingGallery\Service\MediaSummaryService $summaries,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeNodeDeletedEvent || $event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
			$this->invalidateNodeAncestors([$event->getNode()]);
			return;
		}
		if ($event instanceof BeforeNodeRenamedEvent || $event instanceof NodeRenamedEvent) {
			$this->invalidateNodeAncestors([$event->getSource(), $event->getTarget()]);
			return;
		}
		if (!$event instanceof FileCacheUpdated && !$event instanceof NodeAddedToCache && !$event instanceof NodeRemovedFromCache) return;
		$folderIds = $this->ancestors->folderIds($event->getStorage()->getCache(), $event->getPath());
		if (strtolower(pathinfo($event->getPath(), PATHINFO_EXTENSION)) === 'xmp') {
			foreach ($this->sorts->sidecarFileIds($folderIds, pathinfo($event->getPath(), PATHINFO_FILENAME)) as $fileId) $this->metadata->invalidate($fileId);
		}
		$galleries = $this->galleries->findActiveFolderSources($folderIds, true);
		$this->summaries->invalidateMany(array_map(static fn (Gallery $gallery): int => (int)$gallery->getId(), $galleries));
		$activeIds = array_values(array_map(static fn (Gallery $gallery): int => (int)$gallery->getId(), array_filter($galleries, static fn (Gallery $gallery): bool => $gallery->getStatus() !== 'archived')));
		foreach (array_chunk($activeIds, 500) as $batch) $this->jobs->add(RebuildMediaIndexJob::class, count($batch) === 1 ? ['galleryId' => $batch[0]] : ['galleryIds' => $batch]);
	}

	/** The actual owner tree crosses mounted storage boundaries, unlike cache parents.
	 * @param list<\OCP\Files\Node> $nodes
	 */
	private function invalidateNodeAncestors(array $nodes): void {
		$ids = [];
		foreach ($nodes as $node) {
			try {
				while ($node->getId() > 0 && !isset($ids[$node->getId()])) {
					$ids[$node->getId()] = true;
					$node = $node->getParent();
				}
			} catch (\OCP\Files\NotFoundException|\OCP\Files\NotPermittedException) {}
		}
		$this->summaries->invalidateMany(array_map(static fn (Gallery $gallery): int => (int)$gallery->getId(), $this->galleries->findActiveFolderSources(array_keys($ids), true)));
	}
}
