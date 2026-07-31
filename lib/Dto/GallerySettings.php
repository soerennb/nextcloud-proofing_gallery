<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use InvalidArgumentException;
use JsonSerializable;
use OCA\ProofingGallery\Domain\FeedbackVisibility;
use OCA\ProofingGallery\Domain\GalleryMode;

final readonly class GallerySettings implements JsonSerializable {
	public const SCHEMA_VERSION = 2;

	/** @var list<string> */
	public array $colorLabels;
	public FeedbackVisibility $feedbackVisibility;
	public bool $allowDownloads;
	public bool $allowGuestUploads;
	public bool $showFilenames;
	/** @var array<string, mixed> */
	public array $appearance;

	/**
	 * @param array{visibility: string, likes: bool, colors: bool, comments: bool, annotations: bool, selections: bool, colorLabels: list<string>, colorEnabled: list<bool>, selectionWarningThreshold: int} $review
	 * @param array{accentColor: string, welcomeMessage: string, logoFileId: ?int, heroFileId: ?int, openerStyle: string, heroFocusX: int, heroFocusY: int, fontPreset: string, watermarkText: string, watermarkOpacity: int, theme: string, layout: string, tileSize: string, tileGap: string, tileRadius: string, titleAlignment: string, showFilenames: bool} $presentation
	 * @param array{downloadScope: string, contactSheet: bool, guestUploads: bool} $delivery
	 * @param array{folders: bool, sortBy: string, sortDirection: string, groupBy: string} $navigation
	 * @param array{allowModeSwitch: bool, hideRejectedInPresentation: bool} $security
	 */
	public function __construct(
		public int $schemaVersion,
		public GalleryMode $mode,
		public string $publicLocale,
		public array $review,
		public array $presentation,
		public array $delivery,
		public array $navigation,
		public array $security,
	) {
		$this->validate();
		$this->feedbackVisibility = FeedbackVisibility::from($review['visibility']);
		$this->colorLabels = $review['colorLabels'];
		$this->allowDownloads = $delivery['downloadScope'] !== 'none';
		$this->allowGuestUploads = $delivery['guestUploads'];
		$this->showFilenames = $presentation['showFilenames'];
		$this->appearance = $presentation;
	}

	public static function defaults(): self {
		$defaults = self::defaultValues();
		return new self(
			self::SCHEMA_VERSION,
			GalleryMode::Presentation,
			'auto',
			$defaults['review'],
			$defaults['presentation'],
			$defaults['delivery'],
			$defaults['navigation'],
			$defaults['security'],
		);
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$allowed = [
			'schemaVersion', 'mode', 'publicLocale', 'review', 'presentation', 'delivery', 'navigation', 'security',
			// Version 1 compatibility. These aliases can be removed after all supported releases write v2 settings.
			'feedbackVisibility', 'allowDownloads', 'allowGuestUploads', 'showFilenames', 'colorLabels', 'appearance',
		];
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unknown gallery setting: ' . reset($unknown));
		}

		$defaults = self::defaultValues();
		$review = self::object($input, 'review');
		$presentation = self::object($input, 'presentation');
		$delivery = self::object($input, 'delivery');
		$navigation = self::object($input, 'navigation');
		$security = self::object($input, 'security');
		$heroWasProvided = array_key_exists('heroFileId', $presentation)
			|| (is_array($input['appearance'] ?? null) && array_key_exists('heroFileId', $input['appearance']));

		// Legacy fields intentionally win while old clients still submit the compatibility aliases.
		if (array_key_exists('feedbackVisibility', $input)) {
			$review['visibility'] = $input['feedbackVisibility'];
		}
		if (array_key_exists('colorLabels', $input)) {
			$review['colorLabels'] = $input['colorLabels'];
		}
		if (array_key_exists('appearance', $input)) {
			if (!is_array($input['appearance'])) {
				throw new InvalidArgumentException('appearance must be an object');
			}
			$presentation = array_replace($presentation, $input['appearance']);
		}
		if (array_key_exists('allowDownloads', $input)) {
			self::assertBoolean($input['allowDownloads'], 'allowDownloads');
			$delivery['downloadScope'] = $input['allowDownloads'] === true ? 'all' : 'none';
		}
		if (array_key_exists('allowGuestUploads', $input)) {
			self::assertBoolean($input['allowGuestUploads'], 'allowGuestUploads');
			$delivery['guestUploads'] = $input['allowGuestUploads'];
		}
		if (array_key_exists('showFilenames', $input)) {
			self::assertBoolean($input['showFilenames'], 'showFilenames');
			$presentation['showFilenames'] = $input['showFilenames'];
		}

		$review = self::mergeSection('review', $defaults['review'], $review);
		$presentation = self::mergeSection('presentation', $defaults['presentation'], $presentation);
		$delivery = self::mergeSection('delivery', $defaults['delivery'], $delivery);
		$navigation = self::mergeSection('navigation', $defaults['navigation'], $navigation);
		$security = self::mergeSection('security', $defaults['security'], $security);

		$openerWasProvided = array_key_exists('openerStyle', self::object($input, 'presentation'))
			|| (is_array($input['appearance'] ?? null) && array_key_exists('openerStyle', $input['appearance']));
		if (!$openerWasProvided && $heroWasProvided && $presentation['heroFileId'] !== null) {
			$presentation['openerStyle'] = 'cinematic';
		}

		try {
			$mode = GalleryMode::from((string)($input['mode'] ?? GalleryMode::Presentation->value));
		} catch (\ValueError $exception) {
			throw new InvalidArgumentException('Invalid gallery mode', previous: $exception);
		}

		return new self(
			self::SCHEMA_VERSION,
			$mode,
			self::string($input['publicLocale'] ?? 'auto', 'publicLocale'),
			$review,
			$presentation,
			$delivery,
			$navigation,
			$security,
		);
	}

	/** @param array<string, mixed> $patch */
	public static function merge(self $current, array $patch): self {
		$base = $current->canonical();
		foreach (['review', 'presentation', 'delivery', 'navigation', 'security'] as $section) {
			if (isset($patch[$section]) && is_array($patch[$section])) {
				$base[$section] = array_replace($base[$section], $patch[$section]);
				unset($patch[$section]);
			}
		}
		return self::fromArray(array_replace($base, $patch));
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			...$this->canonical(),
			// Temporary read/write aliases keep older frontends and saved presets compatible.
			'feedbackVisibility' => $this->feedbackVisibility->value,
			'allowDownloads' => $this->allowDownloads,
			'allowGuestUploads' => $this->allowGuestUploads,
			'showFilenames' => $this->showFilenames,
			'colorLabels' => $this->colorLabels,
			'appearance' => $this->appearance,
		];
	}

	/** @return array<string, mixed> */
	private function canonical(): array {
		return [
			'schemaVersion' => self::SCHEMA_VERSION,
			'mode' => $this->mode->value,
			'publicLocale' => $this->publicLocale,
			'review' => $this->review,
			'presentation' => $this->presentation,
			'delivery' => $this->delivery,
			'navigation' => $this->navigation,
			'security' => $this->security,
		];
	}

	private function validate(): void {
		if ($this->schemaVersion !== self::SCHEMA_VERSION || !in_array($this->publicLocale, ['auto', 'en', 'de'], true)) {
			throw new InvalidArgumentException('Invalid settings schema or public locale');
		}
		if (!is_string($this->review['visibility'])) {
			throw new InvalidArgumentException('review.visibility must be a string');
		}
		try {
			FeedbackVisibility::from($this->review['visibility']);
		} catch (\ValueError $exception) {
			throw new InvalidArgumentException('Invalid feedback visibility', previous: $exception);
		}
		foreach (['likes', 'colors', 'comments', 'annotations', 'selections'] as $key) {
			self::assertBoolean($this->review[$key], 'review.' . $key);
		}
		if (!is_array($this->review['colorLabels']) || !array_is_list($this->review['colorLabels']) || count($this->review['colorLabels']) !== 4) {
			throw new InvalidArgumentException('Exactly four color labels are required');
		}
		foreach ($this->review['colorLabels'] as $label) {
			if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 40) {
				throw new InvalidArgumentException('Color labels must contain 1 to 40 characters');
			}
		}
		if (!is_array($this->review['colorEnabled']) || count($this->review['colorEnabled']) !== 4) {
			throw new InvalidArgumentException('Exactly four color switches are required');
		}
		foreach ($this->review['colorEnabled'] as $enabled) {
			self::assertBoolean($enabled, 'review.colorEnabled');
		}
		if (!is_int($this->review['selectionWarningThreshold']) || $this->review['selectionWarningThreshold'] < 0 || $this->review['selectionWarningThreshold'] > 1000) {
			throw new InvalidArgumentException('Invalid selection warning threshold');
		}

		$p = $this->presentation;
		foreach (['accentColor', 'welcomeMessage', 'openerStyle', 'fontPreset', 'watermarkText', 'theme', 'layout', 'tileSize', 'tileGap', 'tileRadius', 'titleAlignment'] as $key) {
			self::string($p[$key], 'presentation.' . $key);
		}
		foreach (['logoFileId', 'heroFileId'] as $key) {
			if ($p[$key] !== null && !is_int($p[$key])) {
				throw new InvalidArgumentException('presentation.' . $key . ' must be a file ID or null');
			}
		}
		foreach (['heroFocusX', 'heroFocusY', 'watermarkOpacity'] as $key) {
			if (!is_int($p[$key])) {
				throw new InvalidArgumentException('presentation.' . $key . ' must be an integer');
			}
		}
		self::assertBoolean($p['showFilenames'], 'presentation.showFilenames');
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $p['accentColor'])
			|| mb_strlen($p['welcomeMessage']) > 4000
			|| mb_strlen($p['watermarkText']) > 120
			|| $p['heroFocusX'] < 0 || $p['heroFocusX'] > 100
			|| $p['heroFocusY'] < 0 || $p['heroFocusY'] > 100
			|| $p['watermarkOpacity'] < 5 || $p['watermarkOpacity'] > 80
			|| !in_array($p['openerStyle'], ['compact', 'cinematic'], true)
			|| !in_array($p['fontPreset'], ['system', 'editorial', 'modern'], true)
			|| !in_array($p['theme'], ['auto', 'light', 'dark'], true)
			|| !in_array($p['layout'], ['grid', 'masonry', 'list'], true)
			|| !in_array($p['tileSize'], ['small', 'medium', 'large'], true)
			|| !in_array($p['tileGap'], ['tight', 'normal', 'wide'], true)
			|| !in_array($p['tileRadius'], ['square', 'soft'], true)
			|| !in_array($p['titleAlignment'], ['left', 'center'], true)) {
			throw new InvalidArgumentException('Invalid presentation settings');
		}

		foreach (['contactSheet', 'guestUploads'] as $key) self::assertBoolean($this->delivery[$key], 'delivery.' . $key);
		if (!in_array($this->delivery['downloadScope'], ['none', 'individual', 'selection', 'all'], true)) {
			throw new InvalidArgumentException('Invalid download scope');
		}
		self::assertBoolean($this->navigation['folders'], 'navigation.folders');
		if (!in_array($this->navigation['sortBy'], ['name', 'modified', 'size'], true)
			|| !in_array($this->navigation['sortDirection'], ['asc', 'desc'], true)
			|| !in_array($this->navigation['groupBy'], ['none', 'type'], true)) {
			throw new InvalidArgumentException('Invalid navigation settings');
		}
		foreach (['allowModeSwitch', 'hideRejectedInPresentation'] as $key) self::assertBoolean($this->security[$key], 'security.' . $key);
	}

	/** @return array{review: array<string, mixed>, presentation: array<string, mixed>, delivery: array<string, mixed>, navigation: array<string, mixed>, security: array<string, mixed>} */
	private static function defaultValues(): array {
		return [
			'review' => [
				'visibility' => 'collaborative', 'likes' => true, 'colors' => true, 'comments' => true,
				'annotations' => true, 'selections' => true,
				'colorLabels' => ['Favorit', 'Auswahl', 'Überarbeiten', 'Ablehnen'],
				'colorEnabled' => [true, true, true, true], 'selectionWarningThreshold' => 0,
			],
			'presentation' => [
				'accentColor' => '#1f6f8b', 'welcomeMessage' => '', 'logoFileId' => null, 'heroFileId' => null,
				'openerStyle' => 'compact', 'heroFocusX' => 50, 'heroFocusY' => 50, 'fontPreset' => 'system',
				'watermarkText' => '', 'watermarkOpacity' => 24, 'theme' => 'dark', 'layout' => 'grid',
				'tileSize' => 'medium', 'tileGap' => 'normal', 'tileRadius' => 'soft', 'titleAlignment' => 'left',
				'showFilenames' => true,
			],
			'delivery' => ['downloadScope' => 'none', 'contactSheet' => true, 'guestUploads' => false],
			'navigation' => ['folders' => true, 'sortBy' => 'name', 'sortDirection' => 'asc', 'groupBy' => 'none'],
			'security' => ['allowModeSwitch' => false, 'hideRejectedInPresentation' => false],
		];
	}

	/** @param array<string, mixed> $input @return array<string, mixed> */
	private static function object(array $input, string $key): array {
		if (!array_key_exists($key, $input)) return [];
		if (!is_array($input[$key])) throw new InvalidArgumentException($key . ' must be an object');
		return $input[$key];
	}

	/** @param array<string, mixed> $defaults @param array<string, mixed> $input @return array<string, mixed> */
	private static function mergeSection(string $name, array $defaults, array $input): array {
		$unknown = array_diff(array_keys($input), array_keys($defaults));
		if ($unknown !== []) throw new InvalidArgumentException('Unknown ' . $name . ' setting: ' . reset($unknown));
		return array_replace($defaults, $input);
	}

	private static function assertBoolean(mixed $value, string $key): void {
		if (!is_bool($value)) throw new InvalidArgumentException($key . ' must be a boolean');
	}

	private static function string(mixed $value, string $key): string {
		if (!is_string($value)) throw new InvalidArgumentException($key . ' must be a string');
		return $value;
	}
}
