<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Service\GalleryMediaCountService;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

final class RebuildGalleryMediaCountsJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private GalleryMapper $galleries, private GalleryMediaCountService $service, private GalleryMediaCountRepository $counts, private IJobList $jobs, private LoggerInterface $logger, private \OCA\ProofingGallery\ContextChat\GalleryContentSyncService $contextChat) {
		parent::__construct($time);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$id = (int)($argument['galleryId'] ?? 0);
		if ($id < 1) return;
		try {
			$gallery = $this->galleries->find($id);
			if (!$this->service->run($gallery)) $this->jobs->add(self::class, ['galleryId' => $id]);
			else {
				try { $this->contextChat->sync($id); }
				catch (\Throwable $error) { $this->logger->warning('Gallery count context synchronization failed', ['galleryId' => $id, 'exception' => $error]); }
			}
		} catch (DoesNotExistException) {
			// A queued generation may outlive its gallery.
		} catch (FolderAccessException|\OCP\Files\NotFoundException) {
			$this->counts->update($id, ['state' => 'unavailable', 'updated_at' => $this->time->getTime()]);
		} catch (\Throwable $error) {
			$this->counts->update($id, ['state' => 'error', 'updated_at' => $this->time->getTime()]);
			$this->logger->warning('Gallery media count will be retried', ['galleryId' => $id, 'exception' => $error]);
			$this->jobs->scheduleAfter(self::class, $this->time->getTime() + 60, ['galleryId' => $id]);
		}
	}
}
