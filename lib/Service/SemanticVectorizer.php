<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class SemanticVectorizer {
	private const DIMENSIONS = 128;
	private const ALIASES = [
		'portrait' => ['person', 'people', 'gesicht', 'mensch'], 'wedding' => ['hochzeit', 'braut', 'bräutigam'],
		'landscape' => ['landschaft', 'natur', 'mountain', 'berg'], 'night' => ['nacht', 'dark', 'dunkel'],
		'family' => ['familie', 'kind', 'children'], 'car' => ['auto', 'vehicle', 'fahrzeug'],
	];

	/** @return list<float> */
	public function embed(string $text): array {
		$vector = array_fill(0, self::DIMENSIONS, 0.0);
		foreach ($this->tokens($text) as $token) {
			$tokens = [$token];
			foreach (self::ALIASES as $concept => $aliases) if ($token === $concept || in_array($token, $aliases, true)) $tokens = [$concept, ...$aliases];
			foreach ($tokens as $expanded) {
				$hash = crc32($expanded);
				$vector[$hash % self::DIMENSIONS] += ($hash & 1) === 0 ? 1.0 : -1.0;
			}
		}
		$length = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
		return $length === 0.0 ? $vector : array_map(static fn (float $value): float => $value / $length, $vector);
	}

	/** @return list<string> */
	public function concepts(string $text): array {
		return array_slice(array_values(array_unique($this->tokens($text))), 0, 40);
	}

	/** @param list<float> $left
	 * @param list<float> $right
	 */
	public function similarity(array $left, array $right): float {
		if (count($left) !== count($right)) return -1.0;
		$score = 0.0;
		foreach ($left as $index => $value) $score += $value * $right[$index];
		return $score;
	}

	/** @return list<string> */
	private function tokens(string $text): array {
		$normalized = mb_strtolower(preg_replace('/[_\-.]+/u', ' ', $text) ?? $text);
		preg_match_all('/[\p{L}\p{N}]{2,}/u', $normalized, $matches);
		return array_slice($matches[0], 0, 200);
	}
}
