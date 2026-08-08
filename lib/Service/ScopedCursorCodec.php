<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class ScopedCursorCodec {
	public function encode(string $scope, int $id): string {
		if ($scope === '' || $id < 1) throw new \InvalidArgumentException('Invalid cursor value');
		return rtrim(strtr(base64_encode(json_encode(['scope' => hash('sha256', $scope), 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
	}

	public function decode(?string $cursor, string $scope): ?int {
		if ($cursor === null || $cursor === '') return null;
		$decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
		$data = $decoded === false ? null : json_decode($decoded, true);
		if (!is_array($data) || !is_int($data['id'] ?? null) || $data['id'] < 1 || !hash_equals(hash('sha256', $scope), (string)($data['scope'] ?? ''))) {
			throw new \InvalidArgumentException('Invalid cursor');
		}
		return $data['id'];
	}
}
