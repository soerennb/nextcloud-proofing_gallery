<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\FolderService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\FileCacheUpdated;
use OCP\Files\Events\NodeAddedToCache;
use OCP\Files\Events\NodeRemovedFromCache;

/** @implements IEventListener<FileCacheUpdated|NodeAddedToCache|NodeRemovedFromCache> */
final class MediaIndexCacheListener implements IEventListener {
	public function __construct(
		private GalleryMapper $galleries,
		private FolderService $folders,
		private IJobList $jobs,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof FileCacheUpdated && !$event instanceof NodeAddedToCache && !$event instanceof NodeRemovedFromCache) return;
		$eventStorage = $event->getStorage()->getId();
		$eventPath = trim($event->getPath(), '/');
		foreach ($this->galleries->findIndexCandidates() as $gallery) {
			try {
				$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
				if ($root->getStorage()->getId() !== $eventStorage) continue;
				$rootPath = trim($root->getInternalPath(), '/');
				if ($rootPath !== '' && $eventPath !== $rootPath && !str_starts_with($eventPath . '/', $rootPath . '/')) continue;
				$this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => $gallery->getId()]);
			} catch (\Throwable) {
				// A temporarily unavailable source is handled by source recovery.
			}
		}
	}
}
