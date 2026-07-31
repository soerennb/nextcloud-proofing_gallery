<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Settings;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\HealthService;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\CoreSharingPolicyService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

final class Admin implements ISettings {
	public function __construct(
		private PolicyService $policies,
		private HealthService $health,
		private CoreSharingPolicyService $coreSharing,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'proofing_gallery-admin');
		Util::addScript(Application::APP_ID, 'proofing_gallery-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'policies' => $this->policies->all(),
			'galleryDefaults' => $this->policies->galleryDefaults(),
			'health' => $this->health->status(),
			'instanceSettings' => $this->policies->instanceSettings(),
			'coreSharing' => $this->coreSharing->status(),
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 55;
	}
}
