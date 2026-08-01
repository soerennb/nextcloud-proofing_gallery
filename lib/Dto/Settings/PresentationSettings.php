<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use InvalidArgumentException;
use JsonSerializable;

final class PresentationSettings implements JsonSerializable {
	private function __construct(
		public readonly string $accentColor,
		public readonly string $welcomeMessage,
		public readonly ?int $logoFileId,
		public readonly ?string $instanceLogoAssetId,
		public readonly ?int $heroFileId,
		public readonly string $openerStyle,
		public readonly int $heroFocusX,
		public readonly int $heroFocusY,
		public readonly string $fontPreset,
		public readonly string $watermarkText,
		public readonly int $watermarkOpacity,
		public readonly string $theme,
		public readonly string $layout,
		public readonly string $tileSize,
		public readonly string $tileGap,
		public readonly string $tileRadius,
		public readonly string $titleAlignment,
		public readonly bool $showFilenames,
		public readonly int $slideshowInterval,
	) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return [
			'accentColor' => '#1f6f8b', 'welcomeMessage' => '', 'logoFileId' => null, 'instanceLogoAssetId' => null, 'heroFileId' => null,
			'openerStyle' => 'compact', 'heroFocusX' => 50, 'heroFocusY' => 50, 'fontPreset' => 'system',
			'watermarkText' => '', 'watermarkOpacity' => 24, 'theme' => 'dark', 'layout' => 'grid',
			'tileSize' => 'medium', 'tileGap' => 'normal', 'tileRadius' => 'soft', 'titleAlignment' => 'left',
			'showFilenames' => true, 'slideshowInterval' => 5,
		];
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('presentation', self::defaults(), $input);
		$accent = SettingsInput::string($value['accentColor'], 'presentation.accentColor');
		$welcome = SettingsInput::string($value['welcomeMessage'], 'presentation.welcomeMessage');
		$watermark = SettingsInput::string($value['watermarkText'], 'presentation.watermarkText');
		$x = SettingsInput::int($value['heroFocusX'], 'presentation.heroFocusX');
		$y = SettingsInput::int($value['heroFocusY'], 'presentation.heroFocusY');
		$opacity = SettingsInput::int($value['watermarkOpacity'], 'presentation.watermarkOpacity');
		$interval = SettingsInput::int($value['slideshowInterval'], 'presentation.slideshowInterval');
		$asset = $value['instanceLogoAssetId'];
		if ($asset !== null && (!is_string($asset) || preg_match('/^[A-Za-z0-9]{32}\.(png|jpg|webp|svg)$/', $asset) !== 1)) {
			throw new InvalidArgumentException('presentation.instanceLogoAssetId must be a branding asset ID or null');
		}
		if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1 || mb_strlen($welcome) > 4000 || mb_strlen($watermark) > 120
			|| $x < 0 || $x > 100 || $y < 0 || $y > 100 || $opacity < 5 || $opacity > 80 || $interval < 3 || $interval > 15) {
			throw new InvalidArgumentException('Invalid presentation settings');
		}
		return new self(
			$accent, $welcome,
			SettingsInput::nullableInt($value['logoFileId'], 'presentation.logoFileId'), $asset,
			SettingsInput::nullableInt($value['heroFileId'], 'presentation.heroFileId'),
			SettingsInput::choice($value['openerStyle'], 'presentation.openerStyle', ['compact', 'cinematic']),
			$x, $y, SettingsInput::choice($value['fontPreset'], 'presentation.fontPreset', ['system', 'editorial', 'modern']),
			$watermark, $opacity, SettingsInput::choice($value['theme'], 'presentation.theme', ['auto', 'light', 'dark']),
			SettingsInput::choice($value['layout'], 'presentation.layout', ['grid', 'masonry', 'list']),
			SettingsInput::choice($value['tileSize'], 'presentation.tileSize', ['small', 'medium', 'large']),
			SettingsInput::choice($value['tileGap'], 'presentation.tileGap', ['tight', 'normal', 'wide']),
			SettingsInput::choice($value['tileRadius'], 'presentation.tileRadius', ['square', 'soft']),
			SettingsInput::choice($value['titleAlignment'], 'presentation.titleAlignment', ['left', 'center']),
			SettingsInput::bool($value['showFilenames'], 'presentation.showFilenames'), $interval,
		);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
