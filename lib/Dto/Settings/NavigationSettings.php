<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use InvalidArgumentException;
use JsonSerializable;

final class NavigationSettings implements JsonSerializable {
	private function __construct(
		public readonly bool $folders,
		public readonly bool $recursive,
		public readonly int $groupDepth,
		public readonly string $sortBy,
		public readonly string $sortDirection,
		public readonly string $groupBy,
	) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return ['folders' => true, 'recursive' => false, 'groupDepth' => 1, 'sortBy' => 'name', 'sortDirection' => 'asc', 'groupBy' => 'none'];
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('navigation', self::defaults(), $input);
		$depth = SettingsInput::int($value['groupDepth'], 'navigation.groupDepth');
		if ($depth < 0 || $depth > 8) throw new InvalidArgumentException('Invalid navigation settings');
		return new self(
			SettingsInput::bool($value['folders'], 'navigation.folders'), SettingsInput::bool($value['recursive'], 'navigation.recursive'), $depth,
			SettingsInput::choice($value['sortBy'], 'navigation sort', ['name', 'modified', 'size']),
			SettingsInput::choice($value['sortDirection'], 'navigation direction', ['asc', 'desc']),
			SettingsInput::choice($value['groupBy'], 'navigation grouping', ['none', 'type', 'folder']),
		);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array { return get_object_vars($this); }
}
