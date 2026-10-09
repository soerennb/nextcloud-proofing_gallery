<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Domain\GalleryMode;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;

/** The public feedback contract shared by rendering, reads and mutations. */
final class FeedbackPolicyService {
	public const FEATURES = [
		'likes' => 'likes', 'colors' => 'colors', 'comments' => 'comments',
		'annotations' => 'annotations', 'selections' => 'selections',
		'ratings' => 'guestRatings', 'pick' => 'guestRatings',
	];

	public function __construct(private CapabilityPolicyService $capabilities) {
	}

	/** @return array<string, bool> */
	public function effective(GallerySettings $settings, ?PublicLinkPolicy $link = null): array {
		$features = [];
		foreach (self::FEATURES as $feature => $capability) {
			$features[$feature] = $settings->mode === GalleryMode::Collaboration
				&& $this->capabilities->feature($capability)
				&& $settings->review->enabled($feature)
				&& ($link === null || $link->allows($feature));
		}
		$features['annotations'] = $features['annotations'] && $features['comments'];
		return $features;
	}

	public function publicSettings(GallerySettings $settings, PublicLinkPolicy $link): GallerySettings {
		$serialized = $settings->withPublicPolicy($link)->canonical();
		$features = $this->effective($settings, $link);
		$serialized['review'] = array_replace($serialized['review'], $features);
		if (!in_array(true, $features, true)) $serialized['mode'] = GalleryMode::Presentation->value;
		return GallerySettings::fromArray($serialized);
	}
}
