<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use OCP\FilesMetadata\Event\MetadataLiveEvent;

/** @template-implements IEventListener<MetadataLiveEvent|MetadataBackgroundEvent> */
final class GalleryFilesMetadataProvider implements IEventListener {
	public const SOURCE_KEY = 'proofing-gallery-source';
	public const STATE_KEY = 'proofing-gallery-states';

	public function __construct(private GalleryMapper $galleries) {
	}

	public function handle(Event $event): void {
		if ((!$event instanceof MetadataLiveEvent && !$event instanceof MetadataBackgroundEvent) || !$event->getNode() instanceof Folder) return;
		$galleries = $this->galleries->findActiveFolderSources([$event->getNode()->getId()]);
		$metadata = $event->getMetadata();
		$metadata->setBool(self::SOURCE_KEY, $galleries !== []);
		$metadata->setStringList(self::STATE_KEY, array_values(array_unique(array_map(static fn ($gallery): string => $gallery->getWorkflowState(), $galleries))));
	}
}
