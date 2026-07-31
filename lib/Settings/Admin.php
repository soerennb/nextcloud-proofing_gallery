<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Settings;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\HealthService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

final class Admin implements ISettings {
	public function __construct(
		private PolicyService $policies,
		private HealthService $health,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'proofing_gallery-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'policies' => $this->policies->all(),
			'health' => $this->health->status(),
		]);
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 55;
	}
}
