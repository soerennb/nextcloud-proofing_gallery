<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Settings;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\CapabilityPolicyService;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\UserPreferenceService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;
use OCA\ProofingGallery\Db\PresetMapper;

final class Personal implements ISettings {
	public function __construct(
		private IUserSession $userSession,
		private UserPreferenceService $preferences,
		private CapabilityPolicyService $capabilities,
		private PolicyService $policies,
		private PresetMapper $presets,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) return new TemplateResponse(Application::APP_ID, 'personal', ['preferences' => []]);
		Util::addStyle(Application::APP_ID, 'proofing_gallery-personal');
		Util::addScript(Application::APP_ID, 'proofing_gallery-personal');
		return new TemplateResponse(Application::APP_ID, 'personal', [
			'preferences' => $this->preferences->get($userId),
			'capabilities' => $this->capabilities->effective(userId: $userId),
			'instanceSettings' => $this->policies->instanceSettings(),
			'presets' => $this->presets->findAllOwned($userId),
		]);
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 55;
	}
}
