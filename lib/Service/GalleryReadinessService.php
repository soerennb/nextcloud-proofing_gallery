<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\GalleryNotReadyException;

final class GalleryReadinessService {
	public function __construct(
		private FolderService $folders,
		private MediaSummaryService $summaries,
		private CollectionService $collections,
		private CapabilityPolicyService $capabilities,
	) {
	}

	/** @return array{ready: bool, revision: int, checks: list<array{code: string, state: string, action: string}>} */
	public function evaluate(Gallery $gallery, string $userId): array {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$sourceState = 'blocked';
		$mediaState = 'blocked';
		$artworkState = 'ready';
		$collectionState = null;
		if ($gallery->getSourceType() === 'collection') {
			$status = $this->collections->sourceStatus($gallery);
			$summary = $this->collections->summary($gallery);
			$sourceState = 'ready';
			$mediaState = $summary['total'] > 0 ? 'ready' : 'blocked';
			$collectionState = $status['state'] === 'degraded' ? 'warning' : 'ready';
		} else {
			try {
				$folder = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
				$sourceState = 'ready';
				$mediaState = $this->summaries->forFolder((int)$gallery->getId(), $gallery->getFolderId(), $folder)['total'] > 0
					? 'ready' : 'blocked';
			} catch (FolderAccessException) {
			}
		}
		$checks = [
			['code' => 'source_readable', 'state' => $sourceState, 'action' => 'overview'],
			['code' => 'media_available', 'state' => $mediaState, 'action' => 'content'],
			['code' => 'publishing_allowed', 'state' => $this->capabilities->effective($settings, $userId)['publicPublishing']['allowed'] ? 'ready' : 'blocked', 'action' => 'access'],
		];
		foreach ([$settings->presentation->heroFileId, $settings->presentation->logoFileId] as $fileId) {
			if ($fileId === null) continue;
			try {
				$file = $gallery->getSourceType() === 'collection'
					? $this->collections->resolveMedia($gallery, $fileId)
					: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
				if (!str_starts_with($file->getMimeType(), 'image/')) $artworkState = 'blocked';
			} catch (FolderAccessException|\OCP\Files\NotFoundException) {
				$artworkState = 'blocked';
			}
		}
		$checks[] = ['code' => 'artwork_scoped', 'state' => $artworkState, 'action' => 'style'];
		if ($collectionState !== null) {
			$checks[] = ['code' => 'collection_complete', 'state' => $collectionState, 'action' => 'content'];
		}
		$ready = true;
		foreach ($checks as $check) {
			if ($check['state'] === 'blocked') $ready = false;
		}
		return [
			'ready' => $ready,
			'revision' => $gallery->getRevision(),
			'checks' => $checks,
		];
	}

	public function assertPublishable(Gallery $gallery): void {
		$report = $this->evaluate($gallery, $gallery->getOwnerUid());
		if (!$report['ready']) throw new GalleryNotReadyException($report);
	}
}
