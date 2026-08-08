<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\SetupCheck;

use OCA\ProofingGallery\Service\MigrationStatusService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class SchemaReadinessCheck implements ISetupCheck {
	private const DOCS = 'https://soerennb.github.io/nextcloud-proofing_gallery/en/admin-guide/';

	public function __construct(private MigrationStatusService $migrations, private IL10N $l10n) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Proofing Gallery database schema');
	}

	public function run(): SetupResult {
		try {
			$status = $this->migrations->status();
		} catch (\Throwable) {
			return SetupResult::error($this->l10n->t('The database migration state could not be inspected.'), self::DOCS);
		}
		if ($status['pending'] !== []) {
			return SetupResult::error($this->l10n->n(
				'%n Proofing Gallery database migration is pending.',
				'%n Proofing Gallery database migrations are pending.',
				count($status['pending']),
			), self::DOCS);
		}
		if ($status['missingTables'] !== []) {
			return SetupResult::error($this->l10n->t('The Proofing Gallery database schema is incomplete.'), self::DOCS);
		}
		return SetupResult::success($this->l10n->t('The Proofing Gallery database schema is current.'));
	}
}
