<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\MediaIndex;
use OCA\ProofingGallery\Domain\MediaSort;
use OCA\ProofingGallery\Dto\MediaIndexQuery;
use OCP\Security\ICrypto;

final class MediaCursorCodec {
	public function __construct(private ICrypto $crypto) {
	}

	/** @return array{0: string|int|null, 1: ?int, 2: 'next'|'previous'} */
	public function decode(?string $cursor, MediaIndexQuery $query): array {
		if ($cursor === null || $cursor === '') return [null, null, 'next'];
		if (strlen($cursor) > 16384) throw new \InvalidArgumentException('Invalid media cursor');
		$protected = str_starts_with($cursor, 'c2.');
		$decoded = base64_decode(strtr($protected ? substr($cursor, 3) : $cursor, '-_', '+/'), true);
		try {
			if ($protected && $decoded !== false) $decoded = $this->crypto->decrypt($decoded);
		} catch (\Throwable) {
			throw new \InvalidArgumentException('Invalid media cursor');
		}
		$data = $decoded === false ? null : json_decode($decoded, true);
		$legacy = is_array($data) && !isset($data['version']);
		$value = is_array($data) ? ($data['value'] ?? null) : null;
		if (!is_array($data)
			|| (!$legacy && ($data['version'] ?? null) !== 2)
			|| ($legacy && $query->sortRevision !== '')
			|| (!is_string($value) && !is_int($value) && !($value === null && $query->sortBy === 'capturedAt'))
			|| ($query->sortBy === 'capturedAt' && (!$protected || $legacy))
			|| !is_int($data['fileId'] ?? null) || $data['fileId'] <= 0
			|| !in_array($data['direction'] ?? null, ['next', 'previous'], true)
			|| ($data['sortBy'] ?? null) !== $query->sortBy
			|| ($data['sortDirection'] ?? null) !== $query->sortDirection
			|| ($data['scope'] ?? null) !== $query->cursorScope($legacy)) {
			throw new \InvalidArgumentException('Invalid media cursor');
		}
		if ($query->sortBy === 'name') {
			if (!is_string($value)) throw new \InvalidArgumentException('Invalid media cursor');
			$value = $legacy ? MediaSort::nameKey(basename($value)) : base64_decode($value, true);
			if ($value === false) throw new \InvalidArgumentException('Invalid media cursor');
		} elseif ($value !== null && !is_int($value)) {
			throw new \InvalidArgumentException('Invalid media cursor');
		}
		return [$value, $data['fileId'], $data['direction']];
	}

	/** @param 'next'|'previous' $direction */
	public function encode(MediaIndex $entry, MediaIndexQuery $query, string $direction = 'next'): string {
		if (!in_array($direction, ['next', 'previous'], true)) throw new \InvalidArgumentException('Invalid media cursor direction');
		$value = match ($query->sortBy) {
			'modified' => $entry->getMtime(), 'size' => $entry->getSize(), 'capturedAt' => $entry->getCapturedAt(),
			default => base64_encode($entry->getNaturalName()),
		};
		$json = json_encode([
			'version' => 2, 'value' => $value, 'fileId' => $entry->getFileId(), 'direction' => $direction,
			'sortBy' => $query->sortBy, 'sortDirection' => $query->sortDirection, 'scope' => $query->cursorScope(),
		], JSON_THROW_ON_ERROR);
		$protected = $query->sortBy === 'capturedAt';
		return ($protected ? 'c2.' : '') . rtrim(strtr(base64_encode($protected ? $this->crypto->encrypt($json) : $json), '+/', '-_'), '=');
	}
}
