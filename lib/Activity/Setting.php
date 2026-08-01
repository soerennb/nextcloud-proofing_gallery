<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Activity;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\Activity\ISetting;
use OCP\L10N\IFactory;

final class Setting implements ISetting {
	public function __construct(private IFactory $l10nFactory) {
	}

	public function getIdentifier(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Gallery activity');
	}

	public function getPriority(): int {
		return 70;
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return false;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
