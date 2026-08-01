<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class PublicLinkPolicyService {
	private const BOOLEAN_KEYS = ['view', 'likes', 'colors', 'comments', 'annotations', 'selections', 'ratings', 'pick', 'upload', 'export', 'metadata'];
	private const DOWNLOAD_SCOPES = ['none', 'individual', 'selection', 'all'];

	/** @return array<string, array<string, bool|string>> */
	public function presets(): array {
		$base = array_fill_keys(self::BOOLEAN_KEYS, false);
		$base['view'] = true;
		$base['downloadScope'] = 'none';
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
		$defaults = $this->presets()['presentation'];
		$unknown = array_diff(array_keys($policy), [...self::BOOLEAN_KEYS, 'downloadScope']);
		if ($unknown !== []) throw new \InvalidArgumentException('Unknown public link permission: ' . reset($unknown));
		$result = $defaults;
		foreach (self::BOOLEAN_KEYS as $key) {
			if (array_key_exists($key, $policy)) {
				if (!is_bool($policy[$key])) throw new \InvalidArgumentException($key . ' must be a boolean');
				$result[$key] = $policy[$key];
			}
		}
		if (array_key_exists('downloadScope', $policy)) {
			if (!is_string($policy['downloadScope']) || !in_array($policy['downloadScope'], self::DOWNLOAD_SCOPES, true)) {
				throw new \InvalidArgumentException('Invalid public link download scope');
			}
			$result['downloadScope'] = $policy['downloadScope'];
		}
		return $result;
	}

	/** @param array<string, mixed> ...$layers
	 * @return array<string, bool|string>
	 */
	public function restrict(array ...$layers): array {
		$effective = $this->validate(array_shift($layers) ?? []);
		foreach ($layers as $layer) {
			$next = $this->validate($layer);
			foreach (self::BOOLEAN_KEYS as $key) $effective[$key] = $effective[$key] && $next[$key];
			$effective['downloadScope'] = $this->narrowerDownload((string)$effective['downloadScope'], (string)$next['downloadScope']);
		}
		return $effective;
	}

	private function narrowerDownload(string $left, string $right): string {
		$sets = [
			'none' => [],
			'individual' => ['individual'],
			'selection' => ['selection'],
			'all' => ['individual', 'selection'],
		];
		$allowed = array_values(array_intersect($sets[$left], $sets[$right]));
		return match ($allowed) {
			['individual'] => 'individual',
			['selection'] => 'selection',
			['individual', 'selection'] => 'all',
			default => 'none',
		};
	}
}
