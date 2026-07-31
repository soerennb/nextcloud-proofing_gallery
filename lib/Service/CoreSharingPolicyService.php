<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\IConfig;

final class CoreSharingPolicyService {
	public function __construct(private IConfig $config) {
	}

	/** @return array<string, bool|int|null> */
	public function status(): array {
		return [
			'publicLinksAllowed' => $this->bool('shareapi_allow_links', true),
			'passwordEnforced' => $this->bool('shareapi_enforce_links_password', false),
			'expirationEnabled' => $this->bool('shareapi_default_expire_date', false),
			'expirationEnforced' => $this->bool('shareapi_enforce_expire_date', false),
			'expirationDays' => $this->positiveInt('shareapi_expire_after_n_days'),
			'publicUploadsAllowed' => $this->bool('shareapi_allow_public_upload', true),
		];
	}

	private function bool(string $key, bool $default): bool {
		$value = strtolower($this->config->getAppValue('core', $key, $default ? 'yes' : 'no'));
		return in_array($value, ['1', 'yes', 'true', 'on'], true);
	}

	private function positiveInt(string $key): ?int {
		$value = (int)$this->config->getAppValue('core', $key, '0');
		return $value > 0 ? $value : null;
	}
}
