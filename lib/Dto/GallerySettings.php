<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use InvalidArgumentException;
use JsonSerializable;
use OCA\ProofingGallery\Domain\FeedbackVisibility;
use OCA\ProofingGallery\Domain\GalleryMode;

final readonly class GallerySettings implements JsonSerializable {
	/** @param list<string> $colorLabels */
	public function __construct(
		public GalleryMode $mode,
		public FeedbackVisibility $feedbackVisibility,
		public bool $allowDownloads,
		public bool $allowGuestUploads,
		public bool $showFilenames,
		public array $colorLabels,
		public string $publicLocale,
		/** @var array{accentColor: string, welcomeMessage: string, logoFileId: ?int, heroFileId: ?int, openerStyle: string, heroFocusX: int, heroFocusY: int, fontPreset: string, watermarkText: string, watermarkOpacity: int} */
		public array $appearance,
	) {
		if (count($colorLabels) !== 4) {
			throw new InvalidArgumentException('Exactly four color labels are required');
		}
		if (!in_array($publicLocale, ['auto', 'en', 'de'], true)) {
			throw new InvalidArgumentException('Public locale must be auto, en or de');
		}
		foreach ($colorLabels as $label) {
			if ($label === '' || mb_strlen($label) > 40) {
				throw new InvalidArgumentException('Color labels must contain 1 to 40 characters');
			}
		}
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $appearance['accentColor'])
			|| mb_strlen($appearance['welcomeMessage']) > 1000
			|| mb_strlen($appearance['watermarkText']) > 120
			|| $appearance['heroFocusX'] < 0
			|| $appearance['heroFocusX'] > 100
			|| $appearance['heroFocusY'] < 0
			|| $appearance['heroFocusY'] > 100
			|| !in_array($appearance['openerStyle'], ['compact', 'cinematic'], true)
			|| !in_array($appearance['fontPreset'], ['system', 'editorial', 'modern'], true)
			|| $appearance['watermarkOpacity'] < 5
			|| $appearance['watermarkOpacity'] > 80) {
			throw new InvalidArgumentException('Invalid appearance settings');
		}
	}

	public static function defaults(): self {
		return new self(
			GalleryMode::Presentation,
			FeedbackVisibility::Collaborative,
			false,
			false,
			true,
			['Favorit', 'Auswahl', 'Überarbeiten', 'Ablehnen'],
			'auto',
			[
				'accentColor' => '#1f6f8b',
				'welcomeMessage' => '',
				'logoFileId' => null,
				'heroFileId' => null,
				'openerStyle' => 'compact',
				'heroFocusX' => 50,
				'heroFocusY' => 50,
				'fontPreset' => 'system',
				'watermarkText' => '',
				'watermarkOpacity' => 24,
			],
		);
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$allowed = [
			'mode',
			'feedbackVisibility',
			'allowDownloads',
			'allowGuestUploads',
			'showFilenames',
			'colorLabels',
			'publicLocale',
			'appearance',
		];
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unknown gallery setting: ' . reset($unknown));
		}

		$defaults = self::defaults()->jsonSerialize();
		$settings = array_merge($defaults, $input);
		foreach (['allowDownloads', 'allowGuestUploads', 'showFilenames'] as $booleanKey) {
			if (!is_bool($settings[$booleanKey])) {
				throw new InvalidArgumentException($booleanKey . ' must be a boolean');
			}
		}
		if (!is_array($settings['colorLabels']) || !array_is_list($settings['colorLabels'])) {
			throw new InvalidArgumentException('colorLabels must be a list');
		}
		if (!is_string($settings['publicLocale'])) {
			throw new InvalidArgumentException('publicLocale must be a string');
		}
		foreach ($settings['colorLabels'] as $label) {
			if (!is_string($label)) {
				throw new InvalidArgumentException('Each color label must be a string');
			}
		}
		if (!is_array($settings['appearance'])) {
			throw new InvalidArgumentException('appearance must be an object');
		}
		$appearanceDefaults = $defaults['appearance'];
		$appearanceInput = $settings['appearance'];
		if (!array_key_exists('openerStyle', $appearanceInput)) {
			$appearanceInput['openerStyle'] = ($appearanceInput['heroFileId'] ?? null) === null
				? 'compact'
				: 'cinematic';
		}
		$appearance = array_merge($appearanceDefaults, $appearanceInput);
		$unknownAppearance = array_diff(array_keys($appearance), array_keys($appearanceDefaults));
		if ($unknownAppearance !== []) {
			throw new InvalidArgumentException('Unknown appearance setting: ' . reset($unknownAppearance));
		}
		foreach (['accentColor', 'welcomeMessage', 'openerStyle', 'fontPreset', 'watermarkText'] as $stringKey) {
			if (!is_string($appearance[$stringKey])) {
				throw new InvalidArgumentException($stringKey . ' must be a string');
			}
		}
		foreach (['logoFileId', 'heroFileId'] as $fileKey) {
			if ($appearance[$fileKey] !== null && !is_int($appearance[$fileKey])) {
				throw new InvalidArgumentException($fileKey . ' must be a file ID or null');
			}
		}
		foreach (['heroFocusX', 'heroFocusY', 'watermarkOpacity'] as $integerKey) {
			if (!is_int($appearance[$integerKey])) {
				throw new InvalidArgumentException($integerKey . ' must be an integer');
			}
		}

		try {
			$mode = GalleryMode::from((string)$settings['mode']);
			$visibility = FeedbackVisibility::from((string)$settings['feedbackVisibility']);
		} catch (\ValueError $exception) {
			throw new InvalidArgumentException('Invalid gallery mode or feedback visibility', previous: $exception);
		}

		return new self(
			$mode,
			$visibility,
			$settings['allowDownloads'],
			$settings['allowGuestUploads'],
			$settings['showFilenames'],
			$settings['colorLabels'],
			$settings['publicLocale'],
			$appearance,
		);
	}

	/** @return array<string, bool|string|list<string>> */
	public function jsonSerialize(): array {
		return [
			'mode' => $this->mode->value,
			'feedbackVisibility' => $this->feedbackVisibility->value,
			'allowDownloads' => $this->allowDownloads,
			'allowGuestUploads' => $this->allowGuestUploads,
			'showFilenames' => $this->showFilenames,
			'colorLabels' => $this->colorLabels,
			'publicLocale' => $this->publicLocale,
			'appearance' => $this->appearance,
		];
	}
}
