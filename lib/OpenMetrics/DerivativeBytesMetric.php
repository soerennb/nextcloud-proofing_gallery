<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\OpenMetrics;

use Generator;
use OCP\OpenMetrics\Metric;

final class DerivativeBytesMetric extends AbstractOperationalMetric {
	public function name(): string { return 'proofing_gallery_derivative'; }
	public function unit(): string { return 'bytes'; }
	public function help(): string { return 'Bytes occupied by ready Proofing Gallery video derivatives.'; }

	public function metrics(): Generator {
		yield new Metric($this->health->operationalStatus()['video']['bytes']);
	}
}
