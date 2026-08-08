<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\GalleryListProjectionService;
use OCA\ProofingGallery\Service\ProjectionBackfillState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

final class BackfillGalleryListProjectionJob extends QueuedJob {
	public const STATE_CONFIG_KEY = 'galleryListProjectionV1State';
	private const BATCH_SIZE = 500;
	private const RETRY_DELAY = 60;

	public function __construct(
		ITimeFactory $time,
		private GalleryMapper $galleries,
		private GalleryListProjectionService $projection,
		private IJobList $jobs,
		private ProjectionBackfillState $state,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$projection = ProjectionBackfillState::GALLERY_LIST;
		$afterId = max($this->state->cursor($projection), max(0, (int)($argument['afterId'] ?? 0)));
		if ($this->state->isComplete($projection)) return;

		$this->state->markRunning($projection);
		try {
			$rows = $this->galleries->findLifecycleProjectionBatch($afterId, self::BATCH_SIZE);
			foreach ($rows as $gallery) {
				$this->projection->project($gallery);
				$this->galleries->updateListProjection($gallery);
				$this->state->advance($projection, (int)$gallery->getId());
			}
			if (count($rows) === self::BATCH_SIZE) {
				$this->state->markPending($projection);
				$this->jobs->add(self::class, ['afterId' => $this->state->cursor($projection)]);
				return;
			}
			$this->state->complete($projection);
		} catch (\Throwable $error) {
			$this->state->fail($projection, $error);
			$this->jobs->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_DELAY, ['afterId' => $this->state->cursor($projection)]);
			throw $error;
		}
	}
}
