<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\OpenMetrics;

use Generator;
use OCP\OpenMetrics\Metric;

final class CleanupSuccessMetric extends AbstractOperationalMetric {
	public function name(): string { return 'proofing_gallery_cleanup_last_success_timestamp'; }
	public function unit(): string { return 'seconds'; }
	public function help(): string { return 'Unix timestamp of the last successful Proofing Gallery cleanup.'; }

	public function metrics(): Generator {
		yield new Metric($this->health->operationalStatus()['cleanup']['lastSuccessAt'] ?? 0);
	}
}
