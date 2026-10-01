<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\KioskRepository;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Exception\KioskConflictException;
use OCP\IURLGenerator;
use OCP\AppFramework\Utility\ITimeFactory;

/** Resolves the provisioned share each time; an upload never widens its scope. */
final class KioskLinkService {
	public function __construct(
		private KioskRepository $kiosks,
		private GalleryMapper $galleries,
		private PublicLinkMapper $links,
		private PublicShareContextResolver $contexts,
		private PublicMediaResolver $media,
		private IURLGenerator $urls,
		private CoreSharingPolicyService $sharing,
		private CapabilityPolicyService $capabilities,
		private ITimeFactory $clock,
	) {
	}

	public function gallery(string $ownerUid, int $galleryId): Gallery {
		$gallery = $this->galleries->findOwned($galleryId, $ownerUid);
		if ($this->kiosks->forGallery($galleryId) === null) throw new KioskConflictException('This gallery was not provisioned for a kiosk');
		$this->context($gallery);
		return $gallery;
	}

	public function context(Gallery $gallery): PublicShareContext {
		$this->capabilities->assertCanPublish($gallery->getOwnerUid());
		$policy = $this->sharing->status();
		if (!$policy['publicLinksAllowed']) throw new KioskConflictException('Public links are disabled by Nextcloud');
		$event = $this->kiosks->forGallery((int)$gallery->getId());
		if ($event === null || $event['state'] !== 'ready' || $event['public_link_id'] === null) {
			throw new KioskConflictException('The kiosk gallery is not ready');
		}
		if ($event['owner_uid'] !== $gallery->getOwnerUid() || (int)$event['folder_id'] !== $gallery->getFolderId()
			|| $gallery->getSourceType() !== 'folder' || $gallery->getDeliveryMode() !== 'standard') {
			throw new KioskConflictException('The kiosk gallery source or owner changed');
		}
		$link = $this->links->find((int)$event['public_link_id']);
		$context = $this->contexts->tryResolve($link->getToken());
		if ($context === null || $context->gallery->getId() !== $gallery->getId()) {
			throw new KioskConflictException('The public gallery link is inactive or unavailable');
		}
		if (($context->share->getExpirationDate()?->getTimestamp() ?? PHP_INT_MAX) <= $this->clock->getTime()
			|| ($policy['passwordEnforced'] && $context->share->getPassword() === null)) {
			throw new KioskConflictException('The gallery share expired or requires a password');
		}
		if ($context->link->getStartPath() !== '' || $this->contextsRootDiffers($context, $gallery)) {
			throw new KioskConflictException('The public link no longer covers the kiosk upload folder');
		}
		return $context;
	}

	public function galleryUrl(Gallery $gallery): string {
		$context = $this->context($gallery);
		return $this->urls->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $context->link->getToken()]);
	}

	public function photoUrl(Gallery $gallery, int $fileId): string {
		$context = $this->context($gallery);
		$this->media->resolve($context, $fileId);
		return $this->galleryUrl($gallery) . '?photo=' . $fileId;
	}

	private function contextsRootDiffers(PublicShareContext $context, Gallery $gallery): bool {
		return $context->root->getId() !== $gallery->getFolderId()
			|| $context->link->getScopeMode() !== 'legacy'
			|| $context->link->allowedRootList() !== []
			|| $context->link->getViewMode() !== 'folder';
	}
}
