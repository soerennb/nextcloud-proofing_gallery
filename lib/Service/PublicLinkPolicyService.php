<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;

final class PublicLinkPolicyService {
	public const PERMISSIONS = ['upload', 'export', 'metadata', 'downloadScope'];
	/** @return array<string, bool|string> */
	public function forGallery(Gallery $gallery): array {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		return $this->forSettings($settings);
	}

	/** @return array<string, bool|string> */
	public function forSettings(GallerySettings $settings): array {
		$review = $settings->review;
		$delivery = $settings->delivery;
		return PublicLinkPolicy::fromArray([
			'likes' => $review->likes,
			'colors' => $review->colors,
			'comments' => $review->comments,
			'annotations' => $review->annotations,
			'selections' => $review->selections,
			'ratings' => $review->ratings,
			'pick' => $review->pick,
			'upload' => $delivery->guestUploads,
			'export' => $delivery->downloadScope->value !== 'none',
			'metadata' => $settings->metadata->publicFields !== [],
			'downloadScope' => $delivery->downloadScope->value,
		])->jsonSerialize();
	}

	public function inheritFeedback(PublicLinkPolicy $policy, GallerySettings $settings): PublicLinkPolicy {
		$values = $policy->jsonSerialize();
		foreach (FeedbackPolicyService::FEATURES as $feature => $_capability) $values[$feature] = $settings->review->enabled($feature);
		$values['annotations'] = $values['annotations'] && $values['comments'];
		return PublicLinkPolicy::fromArray($values);
	}

	public function forLink(GallerySettings $settings, \OCA\ProofingGallery\Db\PublicLink $link): PublicLinkPolicy {
		$policy = PublicLinkPolicy::fromArray(json_decode($link->getPolicy(), true, flags: JSON_THROW_ON_ERROR));
		if ($link->getFeedbackPolicyMode() === 'inherit') $policy = $this->inheritFeedback($policy, $settings);
		return $link->getPermissionsPolicyMode() === 'inherit' ? $this->inheritPermissions($policy, $settings) : $policy;
	}

	/** @return array{upload: bool, export: bool, metadata: bool, downloadScope: string} */
	public function permissionDefaults(GallerySettings $settings): array {
		return ['upload' => $settings->delivery->guestUploads, 'export' => true,
			'metadata' => $settings->metadata->publicFields !== [], 'downloadScope' => $settings->delivery->downloadScope->value];
	}

	public function inheritPermissions(PublicLinkPolicy $policy, GallerySettings $settings): PublicLinkPolicy {
		return PublicLinkPolicy::fromArray(array_replace($policy->jsonSerialize(), $this->permissionDefaults($settings)));
	}

	public function effectiveDownloadScope(GallerySettings $settings, PublicLinkPolicy $policy): \OCA\ProofingGallery\Domain\DownloadScope {
		return $settings->delivery->downloadScope->restrict($policy->downloadScope);
	}

	/** Legacy defaults retain zero as the automatic depth marker for upgrade comparison.
	 * @return array{viewMode: string, groupDepth: int}
	 */
	public function navigationDefaults(GallerySettings $settings): array {
		return ['viewMode' => $settings->navigation->recursive ? 'recursive' : 'folder',
			'groupDepth' => $settings->navigation->groupBy === 'folder' ? $settings->navigation->groupDepth : 0];
	}

	/** @return array{viewMode: string, groupDepth: int} */
	public function navigation(GallerySettings $settings, \OCA\ProofingGallery\Db\PublicLink $link): array {
		$value = $link->getNavigationPolicyMode() === 'inherit' ? $this->navigationDefaults($settings)
			: ['viewMode' => $link->getViewMode(), 'groupDepth' => $link->getGroupDepth()];
		$value['groupDepth'] = max(1, $value['groupDepth'] ?: $settings->navigation->groupDepth);
		return $value;
	}

	/** A read-only projection: public consumers must never persist this clone. */
	public function resolvedLink(GallerySettings $settings, \OCA\ProofingGallery\Db\PublicLink $link): \OCA\ProofingGallery\Db\PublicLink {
		$resolved = clone $link;
		$navigation = $this->navigation($settings, $link);
		$resolved->setViewMode($navigation['viewMode']);
		$resolved->setGroupDepth($navigation['groupDepth']);
		return $resolved;
	}

	/** @return array<string, array<string, bool|string>> */
	public function presets(): array {
		$base = PublicLinkPolicy::fromArray([])->jsonSerialize();
		return [
			'presentation' => [...$base],
			'selection' => [...$base, 'likes' => true, 'colors' => true, 'comments' => true, 'selections' => true],
			'proofing' => [...$base, 'comments' => true, 'annotations' => true, 'selections' => true, 'ratings' => true, 'pick' => true],
			'delivery' => [...$base, 'downloadScope' => 'all', 'export' => true, 'metadata' => true],
			'upload' => [...$base, 'upload' => true],
		];
	}

	/** @param array<string, mixed> $policy
	 * @return array<string, bool|string>
	 */
	public function validate(array $policy): array {
		return PublicLinkPolicy::fromArray($policy)->jsonSerialize();
	}

	/** @param array<string, mixed> ...$layers
	 * @return array<string, bool|string>
	 */
	public function restrict(array ...$layers): array {
		$effective = PublicLinkPolicy::fromArray(array_shift($layers) ?? []);
		foreach ($layers as $layer) {
			$effective = $effective->restrict(PublicLinkPolicy::fromArray($layer));
		}
		return $effective->jsonSerialize();
	}
}
