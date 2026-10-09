<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

use OCA\ProofingGallery\Dto\GallerySettings;

final class ProjectSettingsComposer {
	/**
	 * @param array<string, mixed> $instanceDefaults
	 * @param array<string, mixed> ...$overrides Branding, preferences, chosen design and explicit request, in that order.
	 */
	public static function compose(GalleryPurpose $purpose, array $instanceDefaults, array ...$overrides): GallerySettings {
		return GallerySettings::fromArray(array_replace_recursive($instanceDefaults, $purpose->settings(), ...$overrides));
	}
}
