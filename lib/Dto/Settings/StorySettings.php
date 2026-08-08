<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto\Settings;

use InvalidArgumentException;
use JsonSerializable;

final class StorySettings implements JsonSerializable {
	/** @param list<array{id: string, title: string, body: string, style: string, mediaIds: list<int>}> $sections */
	private function __construct(
		public readonly array $sections,
		public readonly bool $showAllMedia,
	) {
	}

	/** @return array{sections: list<mixed>, showAllMedia: bool} */
	public static function defaults(): array {
		return ['sections' => [], 'showAllMedia' => true];
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$value = SettingsInput::merge('presentation.story', self::defaults(), $input);
		if (!is_array($value['sections']) || !array_is_list($value['sections']) || count($value['sections']) > 20) {
			throw new InvalidArgumentException('presentation.story.sections must contain at most 20 sections');
		}
		$sections = [];
		$seenIds = [];
		foreach ($value['sections'] as $index => $section) {
			if (!is_array($section)) throw new InvalidArgumentException("presentation.story.sections.$index must be an object");
			$unknown = array_diff(array_keys($section), ['id', 'title', 'body', 'style', 'mediaIds']);
			if ($unknown !== []) throw new InvalidArgumentException('Unknown story section setting: ' . reset($unknown));
			$id = SettingsInput::string($section['id'] ?? '', "presentation.story.sections.$index.id");
			$title = SettingsInput::string($section['title'] ?? '', "presentation.story.sections.$index.title");
			$body = SettingsInput::string($section['body'] ?? '', "presentation.story.sections.$index.body");
			$style = SettingsInput::choice($section['style'] ?? 'full', "presentation.story.sections.$index.style", ['full', 'split', 'sequence']);
			$mediaIds = $section['mediaIds'] ?? [];
			if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || isset($seenIds[$id]) || mb_strlen($title) > 120 || mb_strlen($body) > 1000
				|| !is_array($mediaIds) || !array_is_list($mediaIds) || count($mediaIds) > 12) {
				throw new InvalidArgumentException("Invalid presentation.story.sections.$index");
			}
			$normalizedIds = [];
			foreach ($mediaIds as $mediaId) {
				if (!is_int($mediaId) || $mediaId < 1) throw new InvalidArgumentException("Invalid presentation.story.sections.$index.mediaIds");
				if (!in_array($mediaId, $normalizedIds, true)) $normalizedIds[] = $mediaId;
			}
			$seenIds[$id] = true;
			$sections[] = ['id' => $id, 'title' => $title, 'body' => $body, 'style' => $style, 'mediaIds' => $normalizedIds];
		}
		return new self($sections, SettingsInput::bool($value['showAllMedia'], 'presentation.story.showAllMedia'));
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
