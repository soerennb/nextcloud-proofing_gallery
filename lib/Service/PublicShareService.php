<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use DateTime;
use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Domain\GalleryStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Db\TTransactional;
use OCP\Constants;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\IDBConnection;
use Throwable;
use OCP\BackgroundJob\IJobList;
use OCA\ProofingGallery\BackgroundJob\WarmGalleryPreviewJob;

final class PublicShareService {
	use TTransactional;

	public function __construct(
		private IManager $shareManager,
		private GalleryMapper $galleries,
		private FolderService $folders,
		private CollectionService $collections,
		private ITimeFactory $clock,
		private CapabilityPolicyService $capabilities,
		private PrimaryPublicLinkSynchronizer $publicLinks,
		private PublicLinkPolicyService $linkPolicies,
		private IJobList $jobs,
		private GalleryReadinessService $readiness,
		private PreviewWarmService $previewWarm,
		private IDBConnection $db,
		private LifecycleScheduleService $lifecycleSchedule,
		private PublicLinkAnchorService $linkAnchors,
		private PublicShareRecoveryService $recovery,
		private PublicShareTargetService $targets,
		private GallerySourceRebindingService $sourceRebinding,
	) {
	}

	public function publish(
		Gallery $gallery,
		?string $password,
		?string $expiresAt,
		string $downloadScope,
		bool $recoverMissingShare = false,
		?string &$recoveryResult = null,
	): Gallery {
		$recoveryResult = null;
		return $this->recovery->locked((int)$gallery->getId(), function () use ($gallery, $password, $expiresAt, $downloadScope, $recoverMissingShare, &$recoveryResult): Gallery {
			$current = $this->galleries->find((int)$gallery->getId());
			if ($current->getRevision() !== $gallery->getRevision()) throw new \OCA\ProofingGallery\Exception\GalleryConflictException('The gallery changed before it could be published');
			return $this->publishLocked($current, $password, $expiresAt, $downloadScope, $recoverMissingShare, $recoveryResult);
		});
	}

	private function publishLocked(Gallery $gallery, ?string $password, ?string $expiresAt, string $downloadScope, bool $recoverMissingShare, ?string &$recoveryResult): Gallery {
		$this->capabilities->assertCanPublish($gallery->getOwnerUid());
		$this->readiness->assertPublishable($gallery);
		if ($downloadScope !== 'none') $this->capabilities->assertFeature('downloads');
		if ($gallery->getStatus() === GalleryStatus::Archived->value) {
			throw new InvalidArgumentException('Archived galleries cannot be published');
		}
		if ($gallery->getSourceType() === 'collection' && $this->collections->availableItems($gallery) === []) {
			throw new InvalidArgumentException('A collection needs at least one available file before publishing');
		}

		$isNewShare = $gallery->getShareToken() === null;
		$previousToken = $gallery->getShareToken();
		$existingLink = $isNewShare ? null : $this->publicLinks->ensurePrimary($gallery);
		if ($gallery->getDeliveryMode() !== 'event' && $existingLink?->getStatus() === 'suspended' && $existingLink->getScopeMode() === 'empty') {
			throw new InvalidArgumentException('Choose valid folders for the disabled public link before publishing');
		}
		$shareTarget = $existingLink === null
			? ($gallery->getDeliveryMode() === 'event' ? $this->linkAnchors->create($gallery->getOwnerUid()) : $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()))
			: $this->targets->resolve($gallery, $existingLink);
		$scopeAnchor = ($existingLink?->getScopeAnchorId() !== null || ($existingLink === null && $gallery->getDeliveryMode() === 'event')) ? $shareTarget : null;
		$share = $this->createShare($gallery, $shareTarget);
		if (!$isNewShare) {
			try {
				$share = $this->recovery->resolve($gallery, $existingLink, (string)$previousToken, (int)$shareTarget->getId());
			} catch (\OCA\ProofingGallery\Exception\PublicShareMissingException $exception) {
				if (!$recoverMissingShare) throw $exception;
				if ($password === null || $expiresAt === null) throw new InvalidArgumentException('Choose a replacement password or no password, and an expiry or no expiry');
				$isNewShare = true;
			}
		}

		$share->setLabel($this->recovery->label($gallery, $existingLink?->getName() ?? 'Primary link'));
		$share->setPermissions(Constants::PERMISSION_READ);
		if (!in_array($downloadScope, ['none', 'individual', 'selection', 'all'], true)) {
			throw new InvalidArgumentException('Invalid download scope');
		}
		$nextSettings = GallerySettings::merge(GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR)), ['delivery' => ['downloadScope' => $downloadScope]]);
		$linkPolicy = $existingLink === null ? \OCA\ProofingGallery\Domain\PublicLinkPolicy::fromArray($this->linkPolicies->permissionDefaults($nextSettings)) : $this->linkPolicies->forLink($nextSettings, $existingLink);
		$share->setHideDownload(!($this->capabilities->feature('downloads') && $this->linkPolicies->effectiveDownloadScope($nextSettings, $linkPolicy)->allowsIndividual()));
		if ($gallery->getShareToken() === null || $password !== null) {
			$share->setPassword($password === '' ? null : $password);
		}
		$share->setExpirationDate($this->expirationDate($expiresAt));

		$share = $isNewShare
			? ($previousToken === null ? $this->shareManager->createShare($share) : $this->recovery->create($share, $previousToken))
			: $this->shareManager->updateShare($share);

		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$gallery->setSettings(json_encode(GallerySettings::merge($settings, [
			'delivery' => ['downloadScope' => $downloadScope],
		]), JSON_THROW_ON_ERROR));
		$gallery->setShareToken($share->getToken());
		$gallery->setStatus(GalleryStatus::Published->value);
		$gallery->setWorkflowState('live');
		$gallery->setPublishedAt($gallery->getPublishedAt() ?? $this->clock->getTime());
		$gallery->setRevokedAt(null);
		$gallery->setUpdatedAt($this->clock->getTime());
		$gallery->setRevision($gallery->getRevision() + 1);
		$this->lifecycleSchedule->project($gallery, $this->clock->getTime());

		try {
			$updated = $this->atomic(function () use ($gallery, $share, $scopeAnchor): Gallery {
				$updated = $this->galleries->update($gallery);
				$this->publicLinks->ensurePrimary($updated, (int)$share->getId(), $scopeAnchor?->getId());
				$this->synchronizeNativeDownloads($updated);
				return $updated;
			}, $this->db);
		} catch (Throwable $exception) {
			$this->failClosedPublishShare($gallery, $share, $isNewShare, $exception);
			throw $exception;
		}
		if ($isNewShare && $previousToken !== null) $recoveryResult = $previousToken === $updated->getShareToken() ? 'restored' : 'replaced';
		try {
			$this->previewWarm->warm($updated);
		} catch (\Throwable) {
			// Publishing remains successful; the queued retry repairs derivatives.
		}
		$this->jobs->add(WarmGalleryPreviewJob::class, ['galleryId' => $updated->getId()]);
		return $updated;
	}

	private function failClosedPublishShare(Gallery $gallery, IShare $share, bool $isNewShare, Throwable $original): void {
		if ($isNewShare && $this->recovery->discard($share, $original)) return;
		if (!$isNewShare) {
			try {
				$share->setPermissions(0);
				$this->shareManager->updateShare($share);
			} catch (Throwable) {
			}
		}
		try {
			$link = $this->publicLinks->ensurePrimary($gallery, (int)$share->getId());
			$this->publicLinks->suspend($link);
		} catch (Throwable) {
			// Nothing more can be changed safely here. The original exception is
			// retained and health reconciliation can surface the orphaned share.
		}
	}

	public function revoke(Gallery $gallery): Gallery {
		return $this->recovery->locked((int)$gallery->getId(), fn (): Gallery => $this->revokeLocked($this->galleries->find((int)$gallery->getId())));
	}

	private function revokeLocked(Gallery $gallery): Gallery {
		foreach ($this->publicLinks->list($gallery) as $link) {
			if (!in_array($link->getStatus(), ['active', 'suspended'], true)) continue;
			try {
				$this->recovery->revoke($gallery, $link);
			} catch (ShareNotFound) {
				// External deletion has already removed access; finish local revocation.
			}
			if ($link->getScopeAnchorId() !== null) {
				try {
					$this->linkAnchors->delete($this->linkAnchors->resolve($gallery->getOwnerUid(), $link->getScopeAnchorId()));
				} catch (\Throwable) {
					// The native share is already gone. Orphan reconciliation can remove
					// an anchor whose best-effort cleanup failed.
				}
				$link->setScopeAnchorId(null);
			}
			$this->publicLinks->markRevoked($link);
		}
		$gallery->setShareToken(null);
		$gallery->setStatus(GalleryStatus::Draft->value);
		$gallery->setRevokedAt($this->clock->getTime());
		$gallery->setUpdatedAt($this->clock->getTime());
		$gallery->setRevision($gallery->getRevision() + 1);
		$this->lifecycleSchedule->project($gallery, $this->clock->getTime());

		return $this->galleries->update($gallery);
	}

	/**
	 * Suspend app-managed link shares without changing their tokens or passwords.
	 * Native permissions are removed before the archived state is committed so
	 * WebDAV and the default Files Sharing page fail closed as well.
	 */
	public function archive(Gallery $gallery): Gallery {
		return $this->recovery->locked((int)$gallery->getId(), fn (): Gallery => $this->archiveLocked($this->galleries->find((int)$gallery->getId())));
	}

	private function archiveLocked(Gallery $gallery): Gallery {
		if ($gallery->getStatus() === GalleryStatus::Archived->value) return $gallery;
		$links = $this->publicLinks->list($gallery);
		/** @var list<array{share: IShare, permissions: int}> $changed */
		$changed = [];
		try {
			foreach ($links as $link) {
				if ($link->getStatus() !== 'active') continue;
				try {
					$share = $this->shareManager->getShareByToken($link->getToken());
				} catch (ShareNotFound) {
					// A missing native share is already inaccessible. Reconciliation can
					// surface it before a later restore attempt.
					continue;
				}
				$permissions = (int)$share->getPermissions();
				if ($permissions === 0) continue;
				$share->setPermissions(0);
				$this->shareManager->updateShare($share);
				$changed[] = ['share' => $share, 'permissions' => $permissions];
			}

			return $this->atomic(function () use ($gallery, $links): Gallery {
				foreach ($links as $link) $this->publicLinks->suspend($link);
				$now = $this->clock->getTime();
				$gallery->setStatus(GalleryStatus::Archived->value);
				$gallery->setArchivedAt($now);
				$gallery->setUpdatedAt($now);
				$gallery->setRevision($gallery->getRevision() + 1);
				$this->lifecycleSchedule->project($gallery, $now);
				return $this->galleries->update($gallery);
			}, $this->db);
		} catch (Throwable $exception) {
			$this->restorePermissions($changed);
			throw $exception;
		}
	}

	public function reconcileArchived(Gallery $gallery): int {
		return $this->recovery->locked((int)$gallery->getId(), fn (): int => $this->reconcileArchivedLocked($this->galleries->find((int)$gallery->getId())));
	}

	private function reconcileArchivedLocked(Gallery $gallery): int {
		if ($gallery->getStatus() !== GalleryStatus::Archived->value) return 0;
		$links = array_values(array_filter(
			$this->publicLinks->list($gallery),
			static fn ($link): bool => $link->getStatus() === 'active',
		));
		foreach ($links as $link) {
			try {
				$share = $this->shareManager->getShareByToken($link->getToken());
				if ((int)$share->getPermissions() !== 0) {
					$share->setPermissions(0);
					$this->shareManager->updateShare($share);
				}
			} catch (ShareNotFound) {
				// Missing native shares are already inaccessible and can be suspended.
			}
		}
		return $this->atomic(function () use ($links): int {
			foreach ($links as $link) $this->publicLinks->suspend($link);
			return count($links);
		}, $this->db);
	}

	/** Restore all suspended native shares before making app routes public. */
	public function restore(Gallery $gallery): Gallery {
		return $this->recovery->locked((int)$gallery->getId(), fn (): Gallery => $this->restoreLocked($this->galleries->find((int)$gallery->getId())));
	}

	private function restoreLocked(Gallery $gallery): Gallery {
		if ($gallery->getStatus() !== GalleryStatus::Archived->value) {
			throw new InvalidArgumentException('Only archived galleries can be restored');
		}
		$links = array_values(array_filter(
			$this->publicLinks->list($gallery),
			static fn ($link): bool => $link->getStatus() === 'suspended' && ($gallery->getDeliveryMode() === 'event' || $link->getScopeMode() !== 'empty'),
		));
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		/** @var list<array{share: IShare, permissions: int, hide: bool}> $changed */
		$changed = [];
		try {
			foreach ($links as $link) {
				try {
					$share = $this->shareManager->getShareByToken($link->getToken());
				} catch (ShareNotFound $exception) {
					throw new InvalidArgumentException('A suspended native share is missing and must be repaired before restore', previous: $exception);
				}
				$hide = !($this->capabilities->feature('downloads') && $this->linkPolicies->effectiveDownloadScope($settings, $this->linkPolicies->forLink($settings, $link))->allowsIndividual());
				$permissions = (int)$share->getPermissions();
				if ($permissions !== Constants::PERMISSION_READ || $share->getHideDownload() !== $hide) {
					$changed[] = ['share' => $share, 'permissions' => $permissions, 'hide' => $share->getHideDownload()];
					$share->setHideDownload($hide);
					$share->setPermissions(Constants::PERMISSION_READ);
					$this->shareManager->updateShare($share);
				}
			}

			return $this->atomic(function () use ($gallery, $links): Gallery {
				foreach ($links as $link) $this->publicLinks->activate($link);
				$gallery->setStatus($links === [] ? GalleryStatus::Draft->value : GalleryStatus::Published->value);
				$gallery->setArchivedAt(null);
				$gallery->setUpdatedAt($this->clock->getTime());
				$gallery->setRevision($gallery->getRevision() + 1);
				$this->lifecycleSchedule->project($gallery, $this->clock->getTime());
				return $this->galleries->update($gallery);
			}, $this->db);
		} catch (Throwable $exception) {
			$this->restorePermissions($changed);
			throw $exception;
		}
	}

	/** @param list<array{share: IShare, permissions: int, hide?: bool}> $changed */
	private function restorePermissions(array $changed): void {
		foreach (array_reverse($changed) as $snapshot) {
			try {
				$snapshot['share']->setPermissions($snapshot['permissions']);
				if (isset($snapshot['hide'])) $snapshot['share']->setHideDownload($snapshot['hide']);
				$this->shareManager->updateShare($snapshot['share']);
			} catch (Throwable $exception) {
				$this->recovery->discard($snapshot['share'], $exception);
			}
		}
	}

	/** Persist a gallery and its native download flags under the same lifecycle lock. */
	public function updateGallery(Gallery $gallery, int $revision): Gallery {
		return $this->recovery->locked((int)$gallery->getId(), function () use ($gallery, $revision): Gallery {
			return $this->atomic(function () use ($gallery, $revision): Gallery {
				$updated = $this->galleries->updateDocument($gallery, $revision);
				$this->synchronizeNativeDownloads($updated);
				return $updated;
			}, $this->db);
		});
	}

	private function synchronizeNativeDownloads(Gallery $gallery): void {
		if ($gallery->getShareToken() === null || $gallery->getStatus() !== GalleryStatus::Published->value) return;
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$changed = [];
		try {
			foreach ($this->publicLinks->list($gallery) as $link) {
				if ($link->getStatus() !== 'active') continue;
				try { $share = $this->shareManager->getShareByToken($link->getToken()); }
				catch (ShareNotFound) { continue; }
				$hide = !($this->capabilities->feature('downloads') && $this->linkPolicies->effectiveDownloadScope($settings, $this->linkPolicies->forLink($settings, $link))->allowsIndividual());
				if ($share->getHideDownload() === $hide) continue;
				$changed[] = ['share' => $share, 'hide' => $share->getHideDownload()];
				$share->setHideDownload($hide);
				$this->shareManager->updateShare($share);
			}
		} catch (Throwable $exception) {
			foreach (array_reverse($changed) as $snapshot) {
				try {
					$snapshot['share']->setHideDownload($snapshot['hide']);
					$this->shareManager->updateShare($snapshot['share']);
				} catch (Throwable) {
					$this->recovery->discard($snapshot['share'], $exception);
				}
			}
			throw $exception;
		}
	}

	public function rebindSource(Gallery $gallery, int $folderId): \OCA\ProofingGallery\Dto\SourceRebindResult {
		return $this->sourceRebinding->rebind($gallery, $folderId);
	}

	private function createShare(Gallery $gallery, ?\OCP\Files\Folder $shareRoot = null): IShare {
		$share = $this->shareManager->newShare();
		$share->setShareType(IShare::TYPE_LINK);
		$share->setNode($shareRoot ?? $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()));
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
