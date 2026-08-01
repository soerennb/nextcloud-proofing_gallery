<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use InvalidArgumentException;
use JsonSerializable;

final class MetadataSettings implements JsonSerializable {
	public const PUBLIC_FIELDS = ['capturedAt', 'camera', 'lens', 'exposure', 'title', 'description', 'creator', 'copyright'];

	/** @param list<string> $publicFields */
	private function __construct(public readonly array $publicFields) {
	}

	/** @return array<string, mixed> */
	public static function defaults(): array { return ['publicFields' => []]; }

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('metadata', self::defaults(), $input)['publicFields'];
		if (!is_array($value) || !array_is_list($value)) throw new InvalidArgumentException('metadata.publicFields must be a list');
		$value = array_map(static fn (mixed $field): string => SettingsInput::string($field, 'metadata.publicFields'), $value);
		foreach ($value as $field) if (!in_array($field, self::PUBLIC_FIELDS, true)) throw new InvalidArgumentException('Invalid public metadata field');
		if (count(array_unique($value)) !== count($value)) throw new InvalidArgumentException('Public metadata fields must be unique');
		return new self($value);
	}

	/** @return array{publicFields: list<string>} */
	public function jsonSerialize(): array { return ['publicFields' => $this->publicFields]; }
}
