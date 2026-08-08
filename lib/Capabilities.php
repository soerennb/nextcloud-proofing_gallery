<?php

declare(strict_types=1);

namespace OCA\ProofingGallery;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IConfig;
use OCP\IL10N;

final class Capabilities implements ICapability {
	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private IAppManager $apps,
	) {
	}

	/** @return array<string, array<string, mixed>> */
	public function getCapabilities(): array {
		$major = (int)explode('.', $this->config->getSystemValueString('version', '0'))[0];
		return [
			Application::APP_ID => [
				'api_version' => 1,
				'agent_api_version' => 2,
				'integrations' => [
					'files_actions' => true,
					'files_sidebar' => $major >= 33,
					'unified_search' => true,
					'smart_picker' => true,
					'dashboard' => true,
					'projects' => $this->apps->isInstalled('related_resources'),
					'flow' => $this->apps->isInstalled('workflowengine'),
					'context_chat' => $major >= 32 && $this->apps->isInstalled('context_chat'),
					'context_agent' => $this->apps->isInstalled('context_agent'),
					'context_agent_maturity' => 'experimental',
					'agent_api_maturity' => 'stable',
					'review_workflow' => true,
					'calendar' => $major >= 31 && $this->apps->isInstalled('calendar'),
					'deck' => $this->apps->isInstalled('deck'),
					'talk' => $this->apps->isInstalled('spreed'),
				],
			],
			'client_integration' => [
				Application::APP_ID => [
					'version' => 0.1,
					'context-menu' => [
						[
							'name' => $this->l10n->t('Open in Proofing Gallery'),
							'url' => '/ocs/v2.php/apps/proofing_gallery/api/v1/files/open/{fileId}',
							'method' => 'GET',
							'icon' => '/apps/proofing_gallery/img/app.svg',
						],
						[
							'name' => $this->l10n->t('Create customer gallery'),
							'url' => '/ocs/v2.php/apps/proofing_gallery/api/v1/files/create/{fileId}',
							'method' => 'POST',
							'mimetype_filters' => 'httpd/unix-directory',
							'icon' => '/apps/proofing_gallery/img/app.svg',
						],
					],
				],
			],
		];
	}
}
