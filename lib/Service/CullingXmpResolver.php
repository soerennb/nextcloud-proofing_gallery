<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class CullingXmpResolver {
	private const MODES = ['report', 'app', 'xmp', 'merge'];
	private const FIELDS = ['rating', 'color', 'pick'];

	/** @param array<string, string> $fieldChoices */
	public function validate(string $mode, array $fieldChoices): void {
		if (!in_array($mode, self::MODES, true)) throw new \InvalidArgumentException('Invalid XMP resolution mode');
		foreach (self::FIELDS as $field) {
			$choice = $fieldChoices[$field] ?? 'app';
			if (!in_array($choice, ['app', 'xmp'], true)) throw new \InvalidArgumentException('Invalid merge field source');
		}
	}

	/**
	 * @param array{fileId: int, rating: int, color: string, pick: string, revision: int} $app
	 * @param array{exists: bool, rating: int, color: string, pick: string} $xmp
	 * @param array<string, string> $fieldChoices
	 * @return array{fileId: int, rating: int, color: string, pick: string, revision: int}
	 */
	public function resolve(string $mode, array $app, array $xmp, array $fieldChoices): array {
		$this->validate($mode, $fieldChoices);
		if ($mode === 'xmp' && $xmp['exists']) return [...$app, 'rating' => $xmp['rating'], 'color' => $xmp['color'], 'pick' => $xmp['pick']];
		if ($mode !== 'merge' || !$xmp['exists']) return $app;
		$result = $app;
		foreach (self::FIELDS as $field) {
			if (($fieldChoices[$field] ?? 'app') === 'xmp') $result[$field] = $xmp[$field];
		}
		return $result;
	}
}
