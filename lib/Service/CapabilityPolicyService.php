<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCP\IGroupManager;

final class CapabilityPolicyService {
	public function __construct(
		private PolicyService $policies,
		private CoreSharingPolicyService $coreSharing,
		private IGroupManager $groups,
	) {
	}

	public function assertCanCreate(string $userId): void {
		if (!$this->allowedForUser($userId, 'galleryCreation', 'creatorGroups')) {
			throw new PolicyViolationException('gallery_creation_disabled', 'Gallery creation is disabled by the administrator');
		}
	}

	public function assertCanPublish(string $userId): void {
		if (!$this->allowedForUser($userId, 'publicPublishing', 'publisherGroups')
			|| $this->coreSharing->status()['publicLinksAllowed'] !== true) {
			throw new PolicyViolationException('public_publishing_disabled', 'Public publishing is disabled by the administrator');
		}
	}

	public function assertFeature(string $feature): void {
		if (!$this->feature($feature)) {
			throw new PolicyViolationException($this->code($feature), 'This feature is disabled by the administrator');
		}
	}

	public function feature(string $feature): bool {
		if (!$this->policies->feature($feature)) return false;
		if ($feature === 'guestUploads') return $this->coreSharing->status()['publicUploadsAllowed'] === true;
		if ($feature === 'publicPublishing') return $this->coreSharing->status()['publicLinksAllowed'] === true;
		return true;
	}

	/** @return array<string, array{allowed: bool, reason: ?string}> */
	public function effective(?GallerySettings $settings = null, ?string $userId = null): array {
		$map = [];
		foreach (['galleryCreation', 'publicPublishing', 'guestUploads', 'downloads', 'emailInvitations', 'nextcloudNotifications', 'likes', 'colors', 'comments', 'annotations', 'selections', 'lifecycleAutomation', 'ownerCulling', 'guestRatings', 'recursiveGalleries', 'multiplePublicLinks'] as $feature) {
			$allowed = $this->feature($feature);
			if ($userId !== null && $feature === 'galleryCreation') $allowed = $this->allowedForUser($userId, $feature, 'creatorGroups');
			if ($userId !== null && $feature === 'publicPublishing') $allowed = $this->allowedForUser($userId, $feature, 'publisherGroups') && $this->feature($feature);
			if ($settings !== null) $allowed = $allowed && $this->enabledInGallery($feature, $settings);
			$map[$feature] = ['allowed' => $allowed, 'reason' => $allowed ? null : $this->code($feature)];
		}
		$map['xmpWriting'] = [
			'allowed' => $this->policies->get('xmpWritingEnabled') === 1,
			'reason' => $this->policies->get('xmpWritingEnabled') === 1 ? null : 'xmp_writing_disabled',
		];
		return $map;
	}

	private function allowedForUser(string $userId, string $feature, string $groupKey): bool {
		if ($this->groups->isAdmin($userId)) return true;
		if (!$this->feature($feature)) return false;
		$allowedGroups = $this->policies->instanceSettings()['access'][$groupKey];
		if ($allowedGroups === []) return true;
		foreach ($allowedGroups as $groupId) {
			if ($this->groups->isInGroup($userId, $groupId)) return true;
		}
		return false;
	}

	private function enabledInGallery(string $feature, GallerySettings $settings): bool {
		return match ($feature) {
			'guestUploads' => $settings->delivery['guestUploads'],
			'downloads' => $settings->delivery['downloadScope'] !== 'none',
			'likes', 'colors', 'comments', 'annotations', 'selections' => $settings->review[$feature],
			'lifecycleAutomation' => $settings->lifecycle['enabled'],
			default => true,
		};
	}

	private function code(string $feature): string {
		return strtolower((string)preg_replace('/([a-z])([A-Z])/', '$1_$2', $feature)) . '_disabled';
	}
}
