<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Db\GalleryMapper;
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
		foreach ($this->galleries->findActiveFolderSources($folderIds, true) as $gallery) {
			$this->summaries->invalidate((int)$gallery->getId());
			if ($gallery->getStatus() !== 'archived') $this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => $gallery->getId()]);
		}
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
		foreach ($this->galleries->findActiveFolderSources(array_keys($ids), true) as $gallery) $this->summaries->invalidate((int)$gallery->getId());
	}
}
