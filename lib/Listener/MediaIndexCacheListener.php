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

/** @implements IEventListener<FileCacheUpdated|NodeAddedToCache|NodeRemovedFromCache> */
final class MediaIndexCacheListener implements IEventListener {
	public function __construct(
		private GalleryMapper $galleries,
		private CacheAncestorResolver $ancestors,
		private IJobList $jobs,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof FileCacheUpdated && !$event instanceof NodeAddedToCache && !$event instanceof NodeRemovedFromCache) return;
		$folderIds = $this->ancestors->folderIds($event->getStorage()->getCache(), $event->getPath());
		foreach ($this->galleries->findActiveFolderSources($folderIds) as $gallery) {
			$this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => $gallery->getId()]);
		}
	}
}
