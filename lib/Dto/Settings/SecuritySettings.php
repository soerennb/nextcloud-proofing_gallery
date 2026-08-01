<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use JsonSerializable;

final class SecuritySettings implements JsonSerializable {
	private function __construct(public readonly bool $allowModeSwitch, public readonly bool $hideRejectedInPresentation) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array { return ['allowModeSwitch' => false, 'hideRejectedInPresentation' => false]; }

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('security', self::defaults(), $input);
		return new self(SettingsInput::bool($value['allowModeSwitch'], 'security.allowModeSwitch'), SettingsInput::bool($value['hideRejectedInPresentation'], 'security.hideRejectedInPresentation'));
	}

	/** @return array{allowModeSwitch: bool, hideRejectedInPresentation: bool} */
	public function jsonSerialize(): array { return get_object_vars($this); }
}
