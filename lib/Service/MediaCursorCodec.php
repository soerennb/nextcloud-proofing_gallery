<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\MediaIndex;
use OCA\ProofingGallery\Dto\MediaIndexQuery;

final class MediaCursorCodec {
	/** @return array{0: string|int|null, 1: ?int, 2: 'next'|'previous'} */
	public function decode(?string $cursor, MediaIndexQuery $query): array {
		if ($cursor === null || $cursor === '') return [null, null, 'next'];
		$decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
		$data = $decoded === false ? null : json_decode($decoded, true);
		$value = is_array($data) ? ($data['value'] ?? null) : null;
		if (!is_array($data)
			|| (!is_string($value) && !is_int($value))
			|| !is_int($data['fileId'] ?? null)
			|| !in_array($data['direction'] ?? null, ['next', 'previous'], true)
			|| ($data['sortBy'] ?? null) !== $query->sortBy
			|| ($data['sortDirection'] ?? null) !== $query->sortDirection
			|| ($data['scope'] ?? null) !== $query->cursorScope()) {
			throw new \InvalidArgumentException('Invalid media cursor');
		}
		return [$value, $data['fileId'], $data['direction']];
	}

	/** @param 'next'|'previous' $direction */
	public function encode(MediaIndex $entry, MediaIndexQuery $query, string $direction = 'next'): string {
		if (!in_array($direction, ['next', 'previous'], true)) throw new \InvalidArgumentException('Invalid media cursor direction');
		$value = match ($query->sortBy) {
			'modified' => $entry->getMtime(),
			'size' => $entry->getSize(),
			default => $entry->getSortKey(),
		};
		return rtrim(strtr(base64_encode(json_encode([
			'value' => $value,
			'fileId' => $entry->getFileId(),
			'direction' => $direction,
			'sortBy' => $query->sortBy,
			'sortDirection' => $query->sortDirection,
			'scope' => $query->cursorScope(),
		], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
	}
}
