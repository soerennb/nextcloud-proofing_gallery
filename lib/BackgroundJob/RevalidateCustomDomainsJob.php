<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\CustomDomainService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

final class RevalidateCustomDomainsJob extends TimedJob {
	public function __construct(ITimeFactory $clock, private CustomDomainService $domains) {
		parent::__construct($clock);
		$this->setInterval(3600);
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		$this->domains->revalidateDue();
	}
}
