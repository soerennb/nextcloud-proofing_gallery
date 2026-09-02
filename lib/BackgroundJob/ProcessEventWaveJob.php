<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\EventWaveService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

final class ProcessEventWaveJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private EventWaveService $waves) {
		parent::__construct($time);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$waveId = (int)($argument['waveId'] ?? 0);
		if ($waveId > 0) $this->waves->process($waveId);
	}
}
