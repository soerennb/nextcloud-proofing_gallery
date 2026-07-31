<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Settings;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection {
	public function __construct(private IL10N $l10n, private IURLGenerator $urlGenerator) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Proofing Gallery');
	}

	public function getPriority(): int {
		return 55;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}
}
