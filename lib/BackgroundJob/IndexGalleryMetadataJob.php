<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\MediaSortRepository;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/** Processes at most the configured metadata batch; unknown dates are completed work. */
final class IndexGalleryMetadataJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private GalleryMapper $galleries,
		private MediaSortRepository $sorts,
		private FolderService $folders,
		private MediaMetadataService $metadata,
		private PolicyService $policies,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$id = (int)($argument['galleryId'] ?? 0);
		if ($id < 1) return;
		try { $gallery = $this->galleries->find($id); } catch (\OCP\AppFramework\Db\DoesNotExistException) { return; }
		if ($gallery->getSourceType() !== 'folder') return;
		$limit = $this->policies->get('metadataBatchSize');
		$lastId = 0;
		foreach ($this->sorts->pending($id, $limit) as $row) {
			$lastId = $row['file_id'];
			try {
				$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $row['file_id']);
				if (!hash_equals($row['etag'], $file->getEtag())) {
					$this->sorts->updateCapture($row['file_id'], $row['etag'], null, 'failed');
					continue;
				}
				$summary = $this->metadata->summary($file);
				if (($summary['state'] ?? '') === 'pending') $summary = $this->metadata->index($file);
				$this->sorts->updateCapture($row['file_id'], $row['etag'], isset($summary['capturedAt']) ? (int)$summary['capturedAt'] : null, 'ready');
			} catch (\Throwable $error) {
				$this->sorts->updateCapture($row['file_id'], $row['etag'], null, 'failed');
				$this->logger->warning('Gallery capture metadata could not be indexed', ['galleryId' => $id, 'fileId' => $row['file_id'], 'exception' => $error]);
			}
		}
		if ($this->sorts->pending($id, 1) !== []) $this->jobs->add(self::class, ['galleryId' => $id, 'afterId' => $lastId]);
	}
}
