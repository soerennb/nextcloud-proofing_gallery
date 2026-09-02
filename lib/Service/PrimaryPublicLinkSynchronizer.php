<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

final class PrimaryPublicLinkSynchronizer {
	public function __construct(
		private PublicLinkMapper $links,
		private PublicLinkPolicyService $policies,
		private PolicyService $instancePolicies,
		private ITimeFactory $clock,
	) {
	}

	/** @return list<PublicLink> */
	public function list(Gallery $gallery): array {
		$this->ensurePrimary($gallery);
		return $this->links->findForGallery($gallery->getId());
	}

	public function ensurePrimary(Gallery $gallery, ?int $coreShareId = null, ?int $scopeAnchorId = null): ?PublicLink {
		try {
			$link = $this->links->findPrimary($gallery->getId());
			if ($gallery->getShareToken() !== null && $link->getToken() !== $gallery->getShareToken()) {
				$link->setToken($gallery->getShareToken());
				$link->setStatus('active');
				$link->setRevokedAt(null);
			}
			if ($coreShareId !== null) $link->setCoreShareId($coreShareId);
			if ($gallery->getDeliveryMode() === 'event') {
				if ($scopeAnchorId !== null) $link->setScopeAnchorId($scopeAnchorId);
				if ($link->getScopeMode() === 'legacy') $link->setScopeMode('empty');
			}
			$this->applyPrimaryNavigation($link, $gallery);
			$link->setUpdatedAt($this->clock->getTime());
			return $this->links->update($link);
		} catch (DoesNotExistException) {
			if ($gallery->getShareToken() === null) return null;
			$now = $this->clock->getTime();
			$link = new PublicLink();
			$link->setGalleryId($gallery->getId());
			$link->setCoreShareId($coreShareId);
			$link->setToken($gallery->getShareToken());
			$link->setName('Primary link');
			$link->setStatus('active');
			$link->setIsPrimary(true);
			$link->setPolicy(json_encode($this->policies->presets()['presentation'], JSON_THROW_ON_ERROR));
			$link->setStartPath('');
			if ($gallery->getDeliveryMode() === 'event') {
				$link->setScopeAnchorId($scopeAnchorId);
				$link->setScopeMode('empty');
			}
			$this->applyPrimaryNavigation($link, $gallery);
			$link->setMinOwnerRating(0);
			$link->setCreatedAt($now);
			$link->setUpdatedAt($now);
			return $this->links->insert($link);
		}
	}

	public function synchronizePrimaryNavigation(Gallery $gallery): void {
		if ($gallery->getShareToken() === null) return;
		$this->ensurePrimary($gallery);
	}

	public function markPrimaryRevoked(Gallery $gallery): void {
		try {
			$link = $this->links->findPrimary($gallery->getId());
			$link->setStatus('revoked');
			$link->setRevokedAt($this->clock->getTime());
			$link->setUpdatedAt($this->clock->getTime());
			$this->links->update($link);
		} catch (DoesNotExistException) {
		}
	}

	public function markRevoked(PublicLink $link): void {
		if ($link->getStatus() === 'revoked') return;
		$link->setStatus('revoked');
		$link->setRevokedAt($this->clock->getTime());
		$link->setUpdatedAt($this->clock->getTime());
		$this->links->update($link);
	}

	public function suspend(PublicLink $link): PublicLink {
		if ($link->getStatus() !== 'active') return $link;
		$link->setStatus('suspended');
		$link->setUpdatedAt($this->clock->getTime());
		return $this->links->update($link);
	}

	public function activate(PublicLink $link): PublicLink {
		if ($link->getStatus() !== 'suspended') return $link;
		$link->setStatus('active');
		$link->setUpdatedAt($this->clock->getTime());
		return $this->links->update($link);
	}

	public function assertBelowLimit(Gallery $gallery): void {
		if ($this->links->countUsableForGallery($gallery->getId()) >= $this->instancePolicies->get('maxPublicLinks')) {
			throw new \InvalidArgumentException('The gallery has reached its public link limit');
		}
	}

	private function applyPrimaryNavigation(PublicLink $link, Gallery $gallery): void {
		$settings = \OCA\ProofingGallery\Dto\GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$link->setPolicy(json_encode($this->policies->validate([
			'view' => true,
			'likes' => $settings->review->likes,
			'colors' => $settings->review->colors,
			'comments' => $settings->review->comments,
			'annotations' => $settings->review->annotations,
			'selections' => $settings->review->selections,
			'ratings' => $settings->review->ratings,
			'pick' => $settings->review->pick,
			'upload' => $settings->delivery->guestUploads,
			'export' => true,
			'metadata' => $settings->metadata->publicFields !== [],
			'downloadScope' => $settings->delivery->downloadScope->value,
		]), JSON_THROW_ON_ERROR));
		$link->setViewMode($settings->navigation->recursive ? 'recursive' : 'folder');
		$link->setGroupDepth($settings->navigation->groupBy === 'folder' ? $settings->navigation->groupDepth : 0);
	}
}
