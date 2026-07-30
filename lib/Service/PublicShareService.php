<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use DateTime;
use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Domain\GalleryStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Share\IManager;
use OCP\Share\IShare;

final class PublicShareService {
	public function __construct(
		private IManager $shareManager,
		private GalleryMapper $galleries,
		private FolderService $folders,
		private ITimeFactory $clock,
	) {
	}

	public function publish(
		Gallery $gallery,
		?string $password,
		?string $expiresAt,
		bool $allowDownloads,
	): Gallery {
		if ($gallery->getStatus() === GalleryStatus::Archived->value) {
			throw new InvalidArgumentException('Archived galleries cannot be published');
		}

		$share = $gallery->getShareToken() === null
			? $this->createShare($gallery)
			: $this->shareManager->getShareByToken($gallery->getShareToken());

		$share->setLabel($gallery->getTitle());
		$share->setPermissions(Constants::PERMISSION_READ);
		$share->setHideDownload(!$allowDownloads);
		if ($gallery->getShareToken() === null || $password !== null) {
			$share->setPassword($password === '' ? null : $password);
		}
		$share->setExpirationDate($this->expirationDate($expiresAt));

		$share = $gallery->getShareToken() === null
			? $this->shareManager->createShare($share)
			: $this->shareManager->updateShare($share);

		$settings = json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR);
		$settings['allowDownloads'] = $allowDownloads;
		$gallery->setSettings(json_encode($settings, JSON_THROW_ON_ERROR));
		$gallery->setShareToken($share->getToken());
		$gallery->setStatus(GalleryStatus::Published->value);
		$gallery->setUpdatedAt($this->clock->getTime());

		return $this->galleries->update($gallery);
	}

	public function revoke(Gallery $gallery): Gallery {
		if ($gallery->getShareToken() !== null) {
			$this->shareManager->deleteShare($this->shareManager->getShareByToken($gallery->getShareToken()));
		}
		$gallery->setShareToken(null);
		$gallery->setStatus(GalleryStatus::Draft->value);
		$gallery->setUpdatedAt($this->clock->getTime());

		return $this->galleries->update($gallery);
	}

	private function createShare(Gallery $gallery): IShare {
		$share = $this->shareManager->newShare();
		$share->setShareType(IShare::TYPE_LINK);
		$share->setNode($this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()));
		$share->setSharedBy($gallery->getOwnerUid());
		$share->setShareOwner($gallery->getOwnerUid());
		return $share;
	}

	private function expirationDate(?string $expiresAt): ?DateTime {
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}
		$date = DateTime::createFromFormat('!Y-m-d', $expiresAt);
		if ($date === false || $date->format('Y-m-d') !== $expiresAt) {
			throw new InvalidArgumentException('Expiration date must use YYYY-MM-DD');
		}
		return $date;
	}
}
