<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use InvalidArgumentException;
use JsonSerializable;
use OCA\ProofingGallery\Domain\FeedbackVisibility;

final class ReviewSettings implements JsonSerializable {
	/**
	 * @param list<string> $colorLabels
	 * @param list<bool> $colorEnabled
	 */
	private function __construct(
		public readonly FeedbackVisibility $visibility,
		public readonly bool $likes,
		public readonly bool $colors,
		public readonly bool $comments,
		public readonly bool $annotations,
		public readonly bool $selections,
		public readonly bool $ratings,
		public readonly bool $pick,
		public readonly array $colorLabels,
		public readonly array $colorEnabled,
		public readonly int $selectionWarningThreshold,
	) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return [
			'visibility' => 'collaborative', 'likes' => true, 'colors' => true, 'comments' => true,
			'annotations' => true, 'selections' => true, 'ratings' => false, 'pick' => false,
			'colorLabels' => ['Favorit', 'Auswahl', 'Überarbeiten', 'Ablehnen'],
			'colorEnabled' => [true, true, true, true], 'selectionWarningThreshold' => 0,
		];
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('review', self::defaults(), $input);
		try {
			$visibility = FeedbackVisibility::from(SettingsInput::string($value['visibility'], 'review.visibility'));
		} catch (\ValueError $exception) {
			throw new InvalidArgumentException('Invalid feedback visibility', previous: $exception);
		}
		$labels = $value['colorLabels'];
		if (!is_array($labels) || !array_is_list($labels) || count($labels) !== 4) throw new InvalidArgumentException('Exactly four color labels are required');
		$labels = array_map(static fn (mixed $label): string => SettingsInput::string($label, 'review.colorLabels'), $labels);
		foreach ($labels as $label) if (trim($label) === '' || mb_strlen($label) > 40) throw new InvalidArgumentException('Color labels must contain 1 to 40 characters');
		$enabled = $value['colorEnabled'];
		if (!is_array($enabled) || !array_is_list($enabled) || count($enabled) !== 4) throw new InvalidArgumentException('Exactly four color switches are required');
		$enabled = array_map(static fn (mixed $entry): bool => SettingsInput::bool($entry, 'review.colorEnabled'), $enabled);
		$threshold = SettingsInput::int($value['selectionWarningThreshold'], 'review.selectionWarningThreshold');
		if ($threshold < 0 || $threshold > 1000) throw new InvalidArgumentException('Invalid selection warning threshold');
		return new self(
			$visibility,
			SettingsInput::bool($value['likes'], 'review.likes'),
			SettingsInput::bool($value['colors'], 'review.colors'),
			SettingsInput::bool($value['comments'], 'review.comments'),
			SettingsInput::bool($value['annotations'], 'review.annotations'),
			SettingsInput::bool($value['selections'], 'review.selections'),
			SettingsInput::bool($value['ratings'], 'review.ratings'),
			SettingsInput::bool($value['pick'], 'review.pick'),
			$labels, $enabled, $threshold,
		);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'visibility' => $this->visibility->value, 'likes' => $this->likes, 'colors' => $this->colors,
			'comments' => $this->comments, 'annotations' => $this->annotations, 'selections' => $this->selections,
			'ratings' => $this->ratings, 'pick' => $this->pick, 'colorLabels' => $this->colorLabels,
			'colorEnabled' => $this->colorEnabled, 'selectionWarningThreshold' => $this->selectionWarningThreshold,
		];
	}

	public function enabled(string $feature): bool {
		return match ($feature) {
			'likes' => $this->likes,
			'colors' => $this->colors,
			'comments' => $this->comments,
			'annotations' => $this->annotations,
			'selections' => $this->selections,
			'ratings' => $this->ratings,
			'pick' => $this->pick,
			default => throw new InvalidArgumentException('Unknown review feature: ' . $feature),
		};
	}
}
