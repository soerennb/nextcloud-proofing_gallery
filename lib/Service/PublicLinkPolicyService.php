<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Domain\PublicLinkPolicy;

final class PublicLinkPolicyService {

	/** @return array<string, array<string, bool|string>> */
	public function presets(): array {
		$base = PublicLinkPolicy::fromArray([])->jsonSerialize();
		return [
			'presentation' => [...$base],
			'selection' => [...$base, 'likes' => true, 'colors' => true, 'comments' => true, 'selections' => true],
			'proofing' => [...$base, 'comments' => true, 'annotations' => true, 'selections' => true, 'ratings' => true, 'pick' => true],
			'delivery' => [...$base, 'downloadScope' => 'all', 'export' => true, 'metadata' => true],
			'upload' => [...$base, 'upload' => true],
		];
	}

	/** @param array<string, mixed> $policy
	 * @return array<string, bool|string>
	 */
	public function validate(array $policy): array {
		return PublicLinkPolicy::fromArray($policy)->jsonSerialize();
	}

	/** @param array<string, mixed> ...$layers
	 * @return array<string, bool|string>
	 */
	public function restrict(array ...$layers): array {
		$effective = PublicLinkPolicy::fromArray(array_shift($layers) ?? []);
		foreach ($layers as $layer) {
			$effective = $effective->restrict(PublicLinkPolicy::fromArray($layer));
		}
		return $effective->jsonSerialize();
	}
}
