<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Domain\PublicLinkCapability;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Domain\GalleryStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Files\Folder;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Share\Exceptions\ShareNotFound;

final class PublicShareContextResolver {
	public function __construct(
		private IManager $shares,
		private GalleryMapper $galleries,
		private PublicLinkMapper $links,
		private FolderService $folders,
		private CapabilityPolicyService $capabilities,
	) {
	}

	public function resolve(string $token, PublicLinkCapability $permission = PublicLinkCapability::View): PublicShareContext {
		return $this->resolveShare($this->shares->getShareByToken($token), $permission);
	}

	public function resolveShare(IShare $share, PublicLinkCapability $permission = PublicLinkCapability::View): PublicShareContext {
		$link = $this->links->findByToken($share->getToken());
		if ($link->getStatus() !== 'active' || $link->getRevokedAt() !== null) throw new \InvalidArgumentException('Public link is inactive');
		$gallery = $this->galleries->find($link->getGalleryId());
		if ($gallery->getStatus() !== GalleryStatus::Published->value || $gallery->getArchivedAt() !== null) {
			throw new \InvalidArgumentException('Public gallery is inactive');
		}
		if (!$link->getIsPrimary() && !$this->capabilities->feature('multiplePublicLinks')) {
			throw new \InvalidArgumentException('Additional public links are disabled');
		}
		if ($link->getViewMode() === 'recursive' && !$this->capabilities->feature('recursiveGalleries')) {
			throw new \InvalidArgumentException('Recursive public galleries are disabled');
		}
		$policy = PublicLinkPolicy::fromArray(json_decode($link->getPolicy(), true, flags: JSON_THROW_ON_ERROR));
		if (!$policy->allows($permission)) throw new \InvalidArgumentException('Public link permission is disabled');
		$galleryRoot = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$expected = $link->getStartPath() === '' ? $galleryRoot : $galleryRoot->get($link->getStartPath());
		if (!$expected instanceof Folder || $share->getNodeId() !== $expected->getId()) throw new \InvalidArgumentException('Public link scope does not match its share');
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		return new PublicShareContext($share, $gallery, $settings, $link, $policy, $expected);
	}

	public function tryResolve(string $token, PublicLinkCapability $permission = PublicLinkCapability::View): ?PublicShareContext {
		try {
			return $this->resolve($token, $permission);
		} catch (ShareNotFound|DoesNotExistException|MultipleObjectsReturnedException|FolderAccessException|\OCP\Files\NotFoundException|\InvalidArgumentException|\JsonException) {
			return null;
		}
	}

	public function tryResolveShare(IShare $share, PublicLinkCapability $permission = PublicLinkCapability::View): ?PublicShareContext {
		try {
			return $this->resolveShare($share, $permission);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|FolderAccessException|\OCP\Files\NotFoundException|\InvalidArgumentException|\JsonException) {
			return null;
		}
	}

	public function isManagedShare(IShare $share): bool {
		try {
			$this->links->findByToken($share->getToken());
			return true;
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return false;
		}
	}
}
