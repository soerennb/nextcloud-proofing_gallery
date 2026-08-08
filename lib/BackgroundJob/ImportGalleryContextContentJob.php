<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\ContextChat\GalleryContentSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

final class ImportGalleryContextContentJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private GalleryContentSyncService $sync,
		private IJobList $jobs,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$next = $this->sync->initialImportBatch(max(0, (int)($argument['afterId'] ?? 0)));
		if ($next !== null) $this->jobs->add(self::class, ['afterId' => $next]);
	}
}
