<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\SetupCheck;

use OCA\ProofingGallery\Service\HealthService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class BackgroundJobsCheck implements ISetupCheck {
	private const DOCS = 'https://soerennb.github.io/nextcloud-proofing_gallery/en/admin-guide/';

	public function __construct(private HealthService $health, private IL10N $l10n) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Proofing Gallery background processing');
	}

	public function run(): SetupResult {
		try {
			$status = $this->health->operationalStatus();
		} catch (\Throwable) {
			return SetupResult::warning($this->l10n->t('Proofing Gallery background processing could not be inspected.'), self::DOCS);
		}
		$cleanup = $status['cleanup'];
		$backlogs = $status['backlogs'];
		$outbox = $status['integrations']['outbox'];
		$maintenance = $status['maintenance'];
		$backfillStates = array_column($maintenance['backfills'], 'status');
		if ($maintenance['periodicJobs']['missing'] !== [] || $maintenance['periodicJobs']['duplicates'] !== []) {
			return SetupResult::error($this->l10n->t('Proofing Gallery background jobs are not registered completely.'), self::DOCS);
		}
		if (in_array('error', $backfillStates, true)) {
			return SetupResult::error($this->l10n->t('Proofing Gallery has a failed data projection that will be retried.'), self::DOCS);
		}
		if ($cleanup['state'] === 'failed' || $outbox['dead'] > 0) {
			return SetupResult::error($this->l10n->t('Proofing Gallery has failed background work that requires attention.'), self::DOCS);
		}
		if ($cleanup['state'] === 'stale' || $status['mediaIndex']['stalled'] > 0 || $backlogs['purges']['due'] > 0 || in_array('running', $backfillStates, true)) {
			return SetupResult::warning($this->l10n->t('Proofing Gallery background work is delayed.'), self::DOCS);
		}
		if ($cleanup['state'] === 'never') {
			return SetupResult::info($this->l10n->t('Proofing Gallery cleanup has not run yet. Cron should run it automatically.'), self::DOCS);
		}
		return SetupResult::success($this->l10n->t('Proofing Gallery background processing is healthy.'));
	}
}
