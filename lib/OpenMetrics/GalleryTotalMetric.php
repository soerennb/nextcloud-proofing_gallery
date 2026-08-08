<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\OpenMetrics;

use Generator;
use OCP\OpenMetrics\Metric;

final class GalleryTotalMetric extends AbstractOperationalMetric {
	public function name(): string { return 'proofing_gallery_galleries_total'; }
	public function help(): string { return 'Number of Proofing Gallery galleries by lifecycle status.'; }

	public function metrics(): Generator {
		foreach ($this->health->operationalStatus()['galleries'] as $status => $count) {
			yield new Metric($count, ['status' => $status]);
		}
	}
}
