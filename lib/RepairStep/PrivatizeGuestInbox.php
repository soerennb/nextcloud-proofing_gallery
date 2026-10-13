<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\RepairStep;

use OCA\ProofingGallery\Service\PrivateGuestInboxService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class PrivatizeGuestInbox implements IRepairStep {
	public function __construct(private PrivateGuestInboxService $inbox) {
	}

	public function getName(): string {
		return 'Move unapproved Proofing Gallery uploads into private appdata';
	}

	public function run(IOutput $output): void {
		do { $count = $this->inbox->migrateBatch(); } while ($count === 100);
	}
}
