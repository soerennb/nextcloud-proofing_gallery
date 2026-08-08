<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\OpenMetrics;

use Generator;
use OCP\OpenMetrics\Metric;

final class IntegrationOutboxMetric extends AbstractOperationalMetric {
	public function name(): string { return 'proofing_gallery_integration_outbox_items'; }
	public function help(): string { return 'Number of Proofing Gallery integration events by delivery state.'; }

	public function metrics(): Generator {
		$outbox = $this->health->operationalStatus()['integrations']['outbox'];
		foreach (['pending', 'retrying', 'dead'] as $state) yield new Metric($outbox[$state], ['state' => $state]);
	}
}
