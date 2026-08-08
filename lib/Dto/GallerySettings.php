<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use InvalidArgumentException;
use JsonSerializable;
use OCA\ProofingGallery\Domain\GalleryMode;
use OCA\ProofingGallery\Domain\PublicLinkCapability;
use OCA\ProofingGallery\Dto\Settings\DeliverySettings;
use OCA\ProofingGallery\Dto\Settings\LifecycleSettings;
use OCA\ProofingGallery\Dto\Settings\MetadataSettings;
use OCA\ProofingGallery\Dto\Settings\NavigationSettings;
use OCA\ProofingGallery\Dto\Settings\PresentationSettings;
use OCA\ProofingGallery\Dto\Settings\ReviewSettings;
use OCA\ProofingGallery\Dto\Settings\SecuritySettings;
use OCA\ProofingGallery\Dto\Settings\SettingsInput;

final class GallerySettings implements JsonSerializable {
	public const SCHEMA_VERSION = 9;
	public const PUBLIC_METADATA_FIELDS = MetadataSettings::PUBLIC_FIELDS;

	private function __construct(
		public readonly GalleryMode $mode,
		public readonly string $publicLocale,
		public readonly ReviewSettings $review,
		public readonly PresentationSettings $presentation,
		public readonly DeliverySettings $delivery,
		public readonly NavigationSettings $navigation,
		public readonly SecuritySettings $security,
		public readonly MetadataSettings $metadata,
		public readonly LifecycleSettings $lifecycle,
	) {
	}

	public static function defaults(): self {
		return self::fromArray([]);
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$allowed = [
			'schemaVersion', 'mode', 'publicLocale', 'review', 'presentation', 'delivery', 'navigation', 'security', 'metadata', 'lifecycle',
			'feedbackVisibility', 'allowDownloads', 'allowGuestUploads', 'showFilenames', 'colorLabels', 'appearance',
		];
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) throw new InvalidArgumentException('Unknown gallery setting: ' . reset($unknown));
		$review = SettingsInput::object($input, 'review');
		$presentation = SettingsInput::object($input, 'presentation');
		$delivery = SettingsInput::object($input, 'delivery');
		$heroProvided = array_key_exists('heroFileId', $presentation);
		$openerProvided = array_key_exists('openerStyle', $presentation);
		if (array_key_exists('feedbackVisibility', $input)) $review['visibility'] = $input['feedbackVisibility'];
		if (array_key_exists('colorLabels', $input)) $review['colorLabels'] = $input['colorLabels'];
		if (array_key_exists('appearance', $input)) {
			$appearance = SettingsInput::object($input, 'appearance');
			$heroProvided = $heroProvided || array_key_exists('heroFileId', $appearance);
			$openerProvided = $openerProvided || array_key_exists('openerStyle', $appearance);
			$presentation = array_replace($presentation, $appearance);
		}
		if (array_key_exists('allowDownloads', $input)) $delivery['downloadScope'] = SettingsInput::bool($input['allowDownloads'], 'allowDownloads') ? 'all' : 'none';
		if (array_key_exists('allowGuestUploads', $input)) $delivery['guestUploads'] = SettingsInput::bool($input['allowGuestUploads'], 'allowGuestUploads');
		if (array_key_exists('showFilenames', $input)) $presentation['showFilenames'] = SettingsInput::bool($input['showFilenames'], 'showFilenames');
		if ($openerProvided && !in_array($presentation['openerStyle'] ?? null, ['minimal', 'compact', 'cinematic'], true)) {
			throw new InvalidArgumentException('Invalid presentation.openerStyle');
		}
		if (!$openerProvided && $heroProvided && ($presentation['heroFileId'] ?? null) !== null) $presentation['openerStyle'] = 'cinematic';
		$schemaVersion = is_int($input['schemaVersion'] ?? null) ? $input['schemaVersion'] : null;
		if (($schemaVersion !== null && $schemaVersion < self::SCHEMA_VERSION)
			|| ($schemaVersion === null && array_key_exists('appearance', $input))) {
			$presentation['openerStyle'] = 'cinematic';
			$presentation['fontPreset'] = 'modern';
			$presentation['showTitle'] = true;
			$presentation['showMediaCount'] = true;
			$presentation['titleSize'] = 'medium';
		}

		try {
			$mode = GalleryMode::from(SettingsInput::string($input['mode'] ?? GalleryMode::Presentation->value, 'mode'));
		} catch (\ValueError $exception) {
			throw new InvalidArgumentException('Invalid gallery mode', previous: $exception);
		}
		$locale = SettingsInput::choice($input['publicLocale'] ?? 'auto', 'public locale', ['auto', 'en', 'de']);
		return new self(
			$mode, $locale, ReviewSettings::fromArray($review), PresentationSettings::fromArray($presentation),
			DeliverySettings::fromArray($delivery), NavigationSettings::fromArray(SettingsInput::object($input, 'navigation')),
			SecuritySettings::fromArray(SettingsInput::object($input, 'security')), MetadataSettings::fromArray(SettingsInput::object($input, 'metadata')),
			LifecycleSettings::fromArray(SettingsInput::object($input, 'lifecycle')),
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
	public function canonical(): array {
		return [
			'schemaVersion' => self::SCHEMA_VERSION, 'mode' => $this->mode->value, 'publicLocale' => $this->publicLocale,
			'review' => $this->review->jsonSerialize(), 'presentation' => $this->presentation->jsonSerialize(),
			'delivery' => $this->delivery->jsonSerialize(), 'navigation' => $this->navigation->jsonSerialize(),
			'security' => $this->security->jsonSerialize(), 'metadata' => $this->metadata->jsonSerialize(), 'lifecycle' => $this->lifecycle->jsonSerialize(),
		];
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		$presentation = $this->presentation->jsonSerialize();
		return [
			...$this->canonical(), 'feedbackVisibility' => $this->review->visibility->value,
			'allowDownloads' => $this->delivery->downloadScope->value !== 'none', 'allowGuestUploads' => $this->delivery->guestUploads,
			'showFilenames' => $this->presentation->showFilenames, 'colorLabels' => $this->review->colorLabels, 'appearance' => $presentation,
		];
	}

	public function withPublicPolicy(\OCA\ProofingGallery\Domain\PublicLinkPolicy $policy): self {
		$settings = $this->canonical();
		$reviewCapabilities = [
			PublicLinkCapability::Likes, PublicLinkCapability::Colors, PublicLinkCapability::Comments,
			PublicLinkCapability::Annotations, PublicLinkCapability::Selections,
			PublicLinkCapability::Ratings, PublicLinkCapability::Pick,
		];
		$reviewEnabled = false;
		foreach ($reviewCapabilities as $capability) {
			$enabled = $settings['review'][$capability->value] && $policy->allows($capability);
			$settings['review'][$capability->value] = $enabled;
			$reviewEnabled = $reviewEnabled || $enabled;
		}
		$settings['delivery']['guestUploads'] = $settings['delivery']['guestUploads'] && $policy->allows(PublicLinkCapability::Upload);
		$settings['delivery']['downloadScope'] = $this->delivery->downloadScope->restrict($policy->downloadScope)->value;
		if (!$policy->allows(PublicLinkCapability::Metadata)) $settings['metadata']['publicFields'] = [];
		if (!$reviewEnabled) $settings['mode'] = GalleryMode::Presentation->value;
		return self::fromArray($settings);
	}
}
