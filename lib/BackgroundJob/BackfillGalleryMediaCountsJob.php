<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\GalleryMediaCountService;
use OCA\ProofingGallery\Service\ProjectionBackfillState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

final class BackfillGalleryMediaCountsJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private GalleryMapper $galleries, private GalleryMediaCountService $counts, private ProjectionBackfillState $state, private IJobList $jobs) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$key = ProjectionBackfillState::MEDIA_COUNTS;
		if ($this->state->isComplete($key)) return;
		$this->state->markRunning($key);
		try {
			$batch = $this->galleries->findLifecycleProjectionBatch($this->state->cursor($key), 100);
			foreach ($batch as $gallery) {
				$this->counts->queue((int)$gallery->getId());
				$this->state->advance($key, (int)$gallery->getId());
			}
			if (count($batch) < 100) $this->state->complete($key);
			else { $this->state->markPending($key); $this->jobs->add(self::class, ['afterId' => $this->state->cursor($key)]); }
		} catch (\Throwable $error) {
			$this->state->fail($key, $error);
			$this->jobs->scheduleAfter(self::class, $this->time->getTime() + 60, ['afterId' => $this->state->cursor($key)]);
			throw $error;
		}
	}
}
