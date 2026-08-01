<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use InvalidArgumentException;
use JsonSerializable;
use OCA\ProofingGallery\Domain\FeedbackVisibility;
use OCA\ProofingGallery\Domain\GalleryMode;

final readonly class GallerySettings implements JsonSerializable {
	public const SCHEMA_VERSION = 5;
	public const PUBLIC_METADATA_FIELDS = [
		'capturedAt', 'camera', 'lens', 'exposure', 'title', 'description', 'creator', 'copyright',
	];

	/** @var list<string> */
	public array $colorLabels;
	public FeedbackVisibility $feedbackVisibility;
	public bool $allowDownloads;
	public bool $allowGuestUploads;
	public bool $showFilenames;
	/** @var array<string, mixed> */
	public array $appearance;

	/**
	 * @param array{visibility: string, likes: bool, colors: bool, comments: bool, annotations: bool, selections: bool, ratings: bool, pick: bool, colorLabels: list<string>, colorEnabled: list<bool>, selectionWarningThreshold: int} $review
	 * @param array{accentColor: string, welcomeMessage: string, logoFileId: ?int, instanceLogoAssetId: ?string, heroFileId: ?int, openerStyle: string, heroFocusX: int, heroFocusY: int, fontPreset: string, watermarkText: string, watermarkOpacity: int, theme: string, layout: string, tileSize: string, tileGap: string, tileRadius: string, titleAlignment: string, showFilenames: bool, slideshowInterval: int} $presentation
	 * @param array{downloadScope: string, contactSheet: bool, guestUploads: bool} $delivery
	 * @param array{folders: bool, recursive: bool, groupDepth: int, sortBy: string, sortDirection: string, groupBy: string} $navigation
	 * @param array{allowModeSwitch: bool, hideRejectedInPresentation: bool} $security
	 * @param array{publicFields: list<string>} $metadata
	 * @param array{enabled: bool, trigger: string, revokeAt: string, revokeAfterDays: int, archiveAfterDays: int, reminderDays: list<int>} $lifecycle
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
		public array $metadata,
		public array $lifecycle,
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
			$defaults['metadata'],
			$defaults['lifecycle'],
		);
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$allowed = [
			'schemaVersion', 'mode', 'publicLocale', 'review', 'presentation', 'delivery', 'navigation', 'security', 'metadata', 'lifecycle',
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
		$metadata = self::object($input, 'metadata');
		$lifecycle = self::object($input, 'lifecycle');
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
		$metadata = self::mergeSection('metadata', $defaults['metadata'], $metadata);
		$lifecycle = self::mergeSection('lifecycle', $defaults['lifecycle'], $lifecycle);

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
			$metadata,
			$lifecycle,
		);
	}

	/** @param array<string, mixed> $patch */
	public static function merge(self $current, array $patch): self {
		$base = $current->canonical();
		foreach (['review', 'presentation', 'delivery', 'navigation', 'security', 'metadata', 'lifecycle'] as $section) {
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
	public function canonical(): array {
		return [
			'schemaVersion' => self::SCHEMA_VERSION,
			'mode' => $this->mode->value,
			'publicLocale' => $this->publicLocale,
			'review' => $this->review,
			'presentation' => $this->presentation,
			'delivery' => $this->delivery,
			'navigation' => $this->navigation,
			'security' => $this->security,
			'metadata' => $this->metadata,
			'lifecycle' => $this->lifecycle,
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
		foreach (['likes', 'colors', 'comments', 'annotations', 'selections', 'ratings', 'pick'] as $key) {
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
		if ($p['instanceLogoAssetId'] !== null
			&& (!is_string($p['instanceLogoAssetId']) || preg_match('/^[A-Za-z0-9]{32}\.(png|jpg|webp|svg)$/', $p['instanceLogoAssetId']) !== 1)) {
			throw new InvalidArgumentException('presentation.instanceLogoAssetId must be a branding asset ID or null');
		}
		foreach (['heroFocusX', 'heroFocusY', 'watermarkOpacity', 'slideshowInterval'] as $key) {
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
			|| $p['slideshowInterval'] < 3 || $p['slideshowInterval'] > 15
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
		self::assertBoolean($this->navigation['recursive'], 'navigation.recursive');
		if (!in_array($this->navigation['sortBy'], ['name', 'modified', 'size'], true)
			|| !in_array($this->navigation['sortDirection'], ['asc', 'desc'], true)
			|| !in_array($this->navigation['groupBy'], ['none', 'type', 'folder'], true)
			|| !is_int($this->navigation['groupDepth'])
			|| $this->navigation['groupDepth'] < 0
			|| $this->navigation['groupDepth'] > 8) {
			throw new InvalidArgumentException('Invalid navigation settings');
		}
		foreach (['allowModeSwitch', 'hideRejectedInPresentation'] as $key) self::assertBoolean($this->security[$key], 'security.' . $key);
		if (!is_array($this->metadata['publicFields']) || !array_is_list($this->metadata['publicFields'])) {
			throw new InvalidArgumentException('metadata.publicFields must be a list');
		}
		foreach ($this->metadata['publicFields'] as $field) {
			if (!is_string($field) || !in_array($field, self::PUBLIC_METADATA_FIELDS, true)) {
				throw new InvalidArgumentException('Invalid public metadata field');
			}
		}
		if (count(array_unique($this->metadata['publicFields'])) !== count($this->metadata['publicFields'])) {
			throw new InvalidArgumentException('Public metadata fields must be unique');
		}
		self::assertBoolean($this->lifecycle['enabled'], 'lifecycle.enabled');
		if (!in_array($this->lifecycle['trigger'], ['fixed_date', 'after_completion'], true)
			|| !is_string($this->lifecycle['revokeAt'])
			|| ($this->lifecycle['revokeAt'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->lifecycle['revokeAt']))
			|| !is_int($this->lifecycle['revokeAfterDays']) || $this->lifecycle['revokeAfterDays'] < 0 || $this->lifecycle['revokeAfterDays'] > 3650
			|| !is_int($this->lifecycle['archiveAfterDays']) || $this->lifecycle['archiveAfterDays'] < 0 || $this->lifecycle['archiveAfterDays'] > 3650
			|| !is_array($this->lifecycle['reminderDays']) || !array_is_list($this->lifecycle['reminderDays'])) {
			throw new InvalidArgumentException('Invalid lifecycle settings');
		}
		foreach ($this->lifecycle['reminderDays'] as $days) {
			if (!is_int($days) || $days < 0 || $days > 365) throw new InvalidArgumentException('Invalid lifecycle reminder');
		}
	}

	/** @return array{review: array<string, mixed>, presentation: array<string, mixed>, delivery: array<string, mixed>, navigation: array<string, mixed>, security: array<string, mixed>, metadata: array<string, mixed>, lifecycle: array<string, mixed>} */
	private static function defaultValues(): array {
		return [
			'review' => [
				'visibility' => 'collaborative', 'likes' => true, 'colors' => true, 'comments' => true,
				'annotations' => true, 'selections' => true, 'ratings' => false, 'pick' => false,
				'colorLabels' => ['Favorit', 'Auswahl', 'Überarbeiten', 'Ablehnen'],
				'colorEnabled' => [true, true, true, true], 'selectionWarningThreshold' => 0,
			],
			'presentation' => [
				'accentColor' => '#1f6f8b', 'welcomeMessage' => '', 'logoFileId' => null, 'instanceLogoAssetId' => null, 'heroFileId' => null,
				'openerStyle' => 'compact', 'heroFocusX' => 50, 'heroFocusY' => 50, 'fontPreset' => 'system',
				'watermarkText' => '', 'watermarkOpacity' => 24, 'theme' => 'dark', 'layout' => 'grid',
				'tileSize' => 'medium', 'tileGap' => 'normal', 'tileRadius' => 'soft', 'titleAlignment' => 'left',
				'showFilenames' => true, 'slideshowInterval' => 5,
			],
			'delivery' => ['downloadScope' => 'none', 'contactSheet' => true, 'guestUploads' => false],
			'navigation' => ['folders' => true, 'recursive' => false, 'groupDepth' => 1, 'sortBy' => 'name', 'sortDirection' => 'asc', 'groupBy' => 'none'],
			'security' => ['allowModeSwitch' => false, 'hideRejectedInPresentation' => false],
			'metadata' => ['publicFields' => []],
			'lifecycle' => [
				'enabled' => false,
				'trigger' => 'after_completion',
				'revokeAt' => '',
				'revokeAfterDays' => 30,
				'archiveAfterDays' => 30,
				'reminderDays' => [7, 1],
			],
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
