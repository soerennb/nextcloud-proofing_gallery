<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\VideoTranscodeService;
use OCP\BackgroundJob\QueuedJob;

final class TranscodeVideoJob extends QueuedJob {
	public function __construct(private VideoTranscodeService $transcodes) {
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$ownerUid = (string)($argument['ownerUid'] ?? '');
		$fileId = (int)($argument['fileId'] ?? 0);
		$etag = (string)($argument['etag'] ?? '');
		if ($ownerUid !== '' && $fileId > 0 && $etag !== '') $this->transcodes->process($ownerUid, $fileId, $etag);
	}
}
