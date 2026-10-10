<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Service\GalleryMediaCountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

final class ReconcileGalleryMediaCountsJob extends TimedJob {
	public function __construct(ITimeFactory $time, private GalleryMediaCountRepository $counts, private GalleryMediaCountService $service) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setAllowParallelRuns(false);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$now = $this->time->getTime();
		foreach ($this->counts->due($now, 100) as $row) {
			$id = (int)$row['id'];
			if (in_array($row['state'], ['ready', 'unavailable'], true)) $this->counts->invalidate($id, $now);
			$this->service->queue($id);
		}
	}
}
