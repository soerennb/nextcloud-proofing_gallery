<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use InvalidArgumentException;
use JsonSerializable;

final class LifecycleSettings implements JsonSerializable {
	/** @param list<int> $reminderDays */
	private function __construct(
		public readonly bool $enabled,
		public readonly string $trigger,
		public readonly string $revokeAt,
		public readonly int $revokeAfterDays,
		public readonly int $archiveAfterDays,
		public readonly array $reminderDays,
		public readonly bool $retentionHandoff,
	) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return ['enabled' => false, 'trigger' => 'after_completion', 'revokeAt' => '', 'revokeAfterDays' => 30, 'archiveAfterDays' => 30, 'reminderDays' => [7, 1], 'retentionHandoff' => false];
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('lifecycle', self::defaults(), $input);
		$revokeAt = SettingsInput::string($value['revokeAt'], 'lifecycle.revokeAt');
		$revokeDays = SettingsInput::int($value['revokeAfterDays'], 'lifecycle.revokeAfterDays');
		$archiveDays = SettingsInput::int($value['archiveAfterDays'], 'lifecycle.archiveAfterDays');
		$reminders = $value['reminderDays'];
		if (($revokeAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $revokeAt) !== 1)
			|| $revokeDays < 0 || $revokeDays > 3650 || $archiveDays < 0 || $archiveDays > 3650
			|| !is_array($reminders) || !array_is_list($reminders)) throw new InvalidArgumentException('Invalid lifecycle settings');
		$reminders = array_map(static fn (mixed $days): int => SettingsInput::int($days, 'lifecycle.reminderDays'), $reminders);
		foreach ($reminders as $days) if ($days < 0 || $days > 365) throw new InvalidArgumentException('Invalid lifecycle reminder');
		return new self(
			SettingsInput::bool($value['enabled'], 'lifecycle.enabled'),
			SettingsInput::choice($value['trigger'], 'lifecycle trigger', ['fixed_date', 'after_completion']),
			$revokeAt, $revokeDays, $archiveDays, $reminders, SettingsInput::bool($value['retentionHandoff'], 'lifecycle.retentionHandoff'),
		);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array { return get_object_vars($this); }
}
