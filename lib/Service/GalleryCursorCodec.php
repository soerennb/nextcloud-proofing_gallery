<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class GalleryCursorCodec {
	/** @param array<string, scalar|null> $scope */
	public function encode(string $sort, int|string $value, int $id, array $scope): string {
		return rtrim(strtr(base64_encode(json_encode([
			's' => $sort,
			'v' => $value,
			'i' => $id,
			'q' => $this->scopeHash($scope),
		], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
	}

	/** @param array<string, scalar|null> $scope
	 * @return array{value: int|string, id: int}|null
	 */
	public function decode(?string $cursor, string $sort, array $scope): ?array {
		if ($cursor === null || $cursor === '') return null;
		$encoded = strtr($cursor, '-_', '+/');
		$encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
		$raw = base64_decode($encoded, true);
		try {
			$data = $raw === false ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$data = null;
		}
		if (!is_array($data) || ($data['s'] ?? null) !== $sort || !is_int($data['i'] ?? null)
			|| !is_string($data['q'] ?? null) || !hash_equals($this->scopeHash($scope), $data['q'])
			|| (!is_int($data['v'] ?? null) && !is_string($data['v'] ?? null))) {
			throw new \InvalidArgumentException('Invalid gallery cursor');
		}
		return ['value' => $data['v'], 'id' => $data['i']];
	}

	/** @param array<string, scalar|null> $scope */
	private function scopeHash(array $scope): string {
		ksort($scope);
		return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
	}
}
