<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;

/** Compatibility and validation for the independently inherited link areas. */
final class PublicLinkInheritanceService {
	public function __construct(private PublicLinkPolicyService $policies) {
	}

	public function configure(Gallery $gallery, PublicLink $link, PublicLinkConfiguration $config): PublicLinkConfiguration {
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$current = $this->policies->forLink($settings, $link)->jsonSerialize();
		$requested = $config->policy->jsonSerialize();
		$feedback = $this->mode($config->feedbackPolicyMode, $link->getFeedbackPolicyMode(), $requested, $current, array_keys(FeedbackPolicyService::FEATURES));
		$permissions = $this->mode($config->permissionsPolicyMode, $link->getPermissionsPolicyMode(), $requested, $current, PublicLinkPolicyService::PERMISSIONS);
		$navigation = $this->policies->navigation($settings, $link);
		$depth = max(1, $config->groupDepth ?: $settings->navigation->groupDepth);
		$navigationMode = $this->mode($config->navigationPolicyMode, $link->getNavigationPolicyMode(),
			['viewMode' => $config->viewMode, 'groupDepth' => $depth], $navigation, ['viewMode', 'groupDepth']);
		if ($config->allowedRoots !== []) {
			if ($config->navigationPolicyMode === 'inherit') throw new \InvalidArgumentException('Multi-folder links cannot inherit navigation');
			$navigationMode = 'custom';
		}
		if (in_array('inherit', [$feedback, $permissions, $navigationMode], true) && (!$link->getIsPrimary() || $gallery->getDeliveryMode() !== 'standard')) {
			throw new \InvalidArgumentException('Only the standard primary link can inherit gallery settings');
		}
		$policy = $config->policy;
		if ($feedback === 'inherit') $policy = $this->policies->inheritFeedback($policy, $settings);
		if ($permissions === 'inherit') $policy = $this->policies->inheritPermissions($policy, $settings);
		$config = $config->withFeedback($policy, $feedback);
		if ($navigationMode === 'inherit') {
			$navigation = $this->policies->navigationDefaults($settings);
			return $config->withInheritance($policy, $permissions, $navigationMode, $navigation['viewMode'], max(1, $navigation['groupDepth'] ?: $settings->navigation->groupDepth));
		}
		return $config->withInheritance($policy, $permissions, $navigationMode, $config->viewMode, $depth);
	}

	/** @param array<string, mixed> $requested
	 * @param array<string, mixed> $current
	 * @param list<string> $keys
	 */
	private function mode(?string $explicit, string $stored, array $requested, array $current, array $keys): string {
		if ($explicit !== null) return $explicit;
		if ($stored === 'inherit') {
			foreach ($keys as $key) if ($requested[$key] !== $current[$key]) return 'custom';
		}
		return $stored;
	}
}
