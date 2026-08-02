<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Http\Client\IClientService;

final class CustomDomainVerifier {
	public function __construct(private IClientService $clients) {
	}

	/** @return array{verified: bool, error: ?string} */
	public function verify(string $domain, string $expectedToken): array {
		if (!$this->hasDnsToken($domain, $expectedToken)) {
			return ['verified' => false, 'error' => 'dns_token_missing'];
		}
		if (!$this->hasOnlyPublicAddresses($domain)) {
			return ['verified' => false, 'error' => 'unsafe_address'];
		}

		try {
			$response = $this->clients->newClient()->get('https://' . $domain . '/status.php', [
				'timeout' => 10, 'connect_timeout' => 5, 'allow_redirects' => false,
				'headers' => ['User-Agent' => 'Nextcloud-Proofing-Gallery-Domain-Verification'],
			]);
		} catch (\Throwable) {
			return ['verified' => false, 'error' => 'tls_unavailable'];
		}

		$status = json_decode($response->getBody(), true);
		if ($response->getStatusCode() !== 200 || !is_array($status) || !is_bool($status['installed'] ?? null)) {
			return ['verified' => false, 'error' => 'nextcloud_unavailable'];
		}

		return ['verified' => true, 'error' => null];
	}

	private function hasDnsToken(string $domain, string $expectedToken): bool {
		$records = dns_get_record('_proofing-gallery.' . $domain, DNS_TXT);
		if (!is_array($records)) {
			return false;
		}
		foreach ($records as $record) {
			$value = (string)($record['txt'] ?? implode('', is_array($record['entries'] ?? null) ? $record['entries'] : []));
			if (hash_equals($expectedToken, trim($value))) {
				return true;
			}
		}
		return false;
	}

	private function hasOnlyPublicAddresses(string $domain): bool {
		$records = dns_get_record($domain, DNS_A | DNS_AAAA);
		if (!is_array($records) || $records === []) {
			return false;
		}

		foreach ($records as $record) {
			$address = $record['ip'] ?? $record['ipv6'] ?? null;
			if (!is_string($address) || filter_var(
				$address,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
			) === false) {
				return false;
			}
		}

		return true;
	}
}
