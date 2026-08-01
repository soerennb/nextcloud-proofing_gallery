<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use JsonSerializable;
use OCA\ProofingGallery\Domain\DownloadScope;

final class DeliverySettings implements JsonSerializable {
	private function __construct(
		public readonly DownloadScope $downloadScope,
		public readonly bool $contactSheet,
		public readonly bool $guestUploads,
	) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return ['downloadScope' => 'none', 'contactSheet' => true, 'guestUploads' => false];
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('delivery', self::defaults(), $input);
		$scope = SettingsInput::choice($value['downloadScope'], 'download scope', array_column(DownloadScope::cases(), 'value'));
		return new self(DownloadScope::from($scope), SettingsInput::bool($value['contactSheet'], 'delivery.contactSheet'), SettingsInput::bool($value['guestUploads'], 'delivery.guestUploads'));
	}

	/** @return array{downloadScope: string, contactSheet: bool, guestUploads: bool} */
	public function jsonSerialize(): array {
		return ['downloadScope' => $this->downloadScope->value, 'contactSheet' => $this->contactSheet, 'guestUploads' => $this->guestUploads];
	}
}
