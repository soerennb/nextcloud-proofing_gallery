<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\OpenMetrics;

use Generator;
use OCP\OpenMetrics\Metric;

final class BacklogMetric extends AbstractOperationalMetric {
	public function name(): string { return 'proofing_gallery_backlog_items'; }
	public function help(): string { return 'Number of queued Proofing Gallery maintenance items.'; }

	public function metrics(): Generator {
		$backlogs = $this->health->operationalStatus()['backlogs'];
		yield new Metric($backlogs['purges']['due'], ['queue' => 'purge_due']);
		yield new Metric($backlogs['lifecycleDue'], ['queue' => 'lifecycle_due']);
		yield new Metric($backlogs['expiredGuests'], ['queue' => 'expired_guests']);
		yield new Metric($backlogs['mediaFolders'], ['queue' => 'media_scan_folders']);
	}
}
