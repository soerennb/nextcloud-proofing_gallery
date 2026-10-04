<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\ProjectionBackfillState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

final class BackfillMediaSortJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private GalleryMapper $galleries, private IJobList $jobs, private ProjectionBackfillState $state) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$key = ProjectionBackfillState::MEDIA_SORT;
		if ($this->state->isComplete($key)) return;
		$this->state->markRunning($key);
		try {
			$rows = $this->galleries->findLifecycleProjectionBatch($this->state->cursor($key), 100);
			foreach ($rows as $gallery) {
				if ($gallery->getSourceType() === 'folder') $this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => (int)$gallery->getId()]);
				$this->state->advance($key, (int)$gallery->getId());
			}
			if (count($rows) < 100) $this->state->complete($key);
			else {
				$this->state->markPending($key);
				$this->jobs->add(self::class, ['afterId' => $this->state->cursor($key)]);
			}
		} catch (\Throwable $error) {
			$this->state->fail($key, $error);
			$this->jobs->add(self::class, ['afterId' => $this->state->cursor($key)]);
			throw $error;
		}
	}
}
