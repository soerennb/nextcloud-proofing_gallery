<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use DateTime;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Share\Exceptions\ShareNotFound;
use Psr\Log\LoggerInterface;

final class PublicLinkManagerService {
	use TTransactional;

	public function __construct(
		private PublicLinkMapper $links,
		private GalleryMapper $galleries,
		private PublicLinkPolicyService $policies,
		private PublicLinkScopeService $scopes,
		private PrimaryPublicLinkSynchronizer $primaryLinks,
		private FolderService $folders,
		private IManager $shareManager,
		private ITimeFactory $clock,
		private IURLGenerator $urls,
		private ShareAuditService $audit,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{items: list<array<string, mixed>>, presets: array<string, array<string, bool|string>>} */
	public function list(Gallery $gallery): array {
		return [
			'items' => array_map(fn (PublicLink $link): array => $this->present($link), $this->primaryLinks->list($gallery)),
			'presets' => $this->policies->presets(),
		];
	}

	/** @return array<string, mixed> */
	public function create(Gallery $gallery, PublicLinkConfiguration $config): array {
		$this->primaryLinks->assertBelowLimit($gallery);
		$config = $this->validateScope($gallery, $config);
		$share = $this->newShare($gallery);
		$this->applyShare($share, $gallery, $config, true);
		$share = $this->shareManager->createShare($share);
		$now = $this->clock->getTime();
		$link = new PublicLink();
		$link->setGalleryId($gallery->getId());
		$link->setCoreShareId((int)$share->getId());
		$link->setToken($share->getToken());
		$link->setName($config->name);
		$link->setStatus('active');
		$link->setIsPrimary(false);
		$link->setPolicy(json_encode($config->policy, JSON_THROW_ON_ERROR));
		$link->setStartPath($config->startPath);
		$link->setViewMode($config->viewMode);
		$link->setGroupDepth($config->groupDepth);
		$link->setMinOwnerRating($config->minOwnerRating);
		$link->setPublicLocale($config->publicLocale);
		$link->setCreatedAt($now);
		$link->setUpdatedAt($now);
		try {
			return $this->present($this->links->insert($link));
		} catch (\Throwable $exception) {
			try {
				$this->shareManager->deleteShare($share);
			} catch (\Throwable $compensation) {
				$this->logCompensationFailure('delete a newly created public share', $compensation, $exception);
			}
			throw $exception;
		}
	}

	/** @return array<string, mixed> */
	public function update(Gallery $gallery, int $linkId, PublicLinkConfiguration $config): array {
		$link = $this->owned($gallery, $linkId);
		if ($link->getStatus() !== 'active') throw new \InvalidArgumentException('Revoked links cannot be edited');
		$config = $this->validateScope($gallery, $config);
		$share = $this->shareManager->getShareByToken($link->getToken());
		$snapshot = $this->shareSnapshot($share);
		$this->applyShare($share, $gallery, $config, false);
		$this->shareManager->updateShare($share);
		$link->setName($config->name);
		$link->setPolicy(json_encode($config->policy, JSON_THROW_ON_ERROR));
		$link->setStartPath($config->startPath);
		$link->setViewMode($config->viewMode);
		$link->setGroupDepth($config->groupDepth);
		$link->setMinOwnerRating($config->minOwnerRating);
		$link->setPublicLocale($config->publicLocale);
		$link->setUpdatedAt($this->clock->getTime());
		try {
			return $this->present($this->links->update($link));
		} catch (\Throwable $exception) {
			$this->compensateShare($share, $snapshot, $exception);
			throw $exception;
		}
	}

	/** @return array<string, mixed> */
	public function makePrimary(Gallery $gallery, int $linkId): array {
		$link = $this->owned($gallery, $linkId);
		if ($link->getStatus() !== 'active') throw new \InvalidArgumentException('Only active links can be primary');
		$link = $this->atomic(function () use ($gallery, $link): PublicLink {
			$this->links->clearPrimary($gallery->getId());
			$link->setIsPrimary(true);
			$link->setUpdatedAt($this->clock->getTime());
			$link = $this->links->update($link);
			$gallery->setShareToken($link->getToken());
			$gallery->setUpdatedAt($this->clock->getTime());
			$gallery->setRevision($gallery->getRevision() + 1);
			$this->galleries->update($gallery);
			return $link;
		}, $this->db);
		return $this->present($link);
	}

	/** @return array<string, mixed> */
	public function revoke(Gallery $gallery, int $linkId, string $actorUid): array {
		$link = $this->owned($gallery, $linkId);
		if ($link->getIsPrimary()) throw new \InvalidArgumentException('Use the legacy revoke action for the primary link');
		if ($link->getStatus() === 'active') {
			try {
				$this->shareManager->deleteShare($this->shareManager->getShareByToken($link->getToken()));
			} catch (ShareNotFound) {
				// An already absent native share is safe to finalize as revoked locally.
			}
			$this->audit->record($link, 'revoke', actorUid: $actorUid);
			$link->setStatus('revoked');
			$link->setRevokedAt($this->clock->getTime());
			$link->setUpdatedAt($this->clock->getTime());
			$link = $this->links->update($link);
		}
		return $this->present($link);
	}

	/** @return array<string, mixed> */
	private function present(PublicLink $link): array {
		return [
			...$link->jsonSerialize(),
			'url' => $this->urls->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $link->getToken()]),
		];
	}

	private function owned(Gallery $gallery, int $linkId): PublicLink {
		try { $link = $this->links->find($linkId); } catch (DoesNotExistException) { throw new \InvalidArgumentException('Public link not found'); }
		if ($link->getGalleryId() !== $gallery->getId()) throw new \InvalidArgumentException('Public link not found');
		return $link;
	}

	private function validateScope(Gallery $gallery, PublicLinkConfiguration $config): PublicLinkConfiguration {
		$startPath = $this->scopes->normalize($config->startPath);
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		if ($startPath !== '') {
			$node = $root->get($startPath);
			if (!$node instanceof Folder || !$root->isSubNode($node)) throw new \InvalidArgumentException('Public link start folder not found');
		}
		return $config->withStartPath($startPath);
	}

	private function newShare(Gallery $gallery): IShare {
		$share = $this->shareManager->newShare();
		$share->setShareType(IShare::TYPE_LINK);
		$share->setNode($this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()));
		$share->setSharedBy($gallery->getOwnerUid());
		$share->setShareOwner($gallery->getOwnerUid());
		return $share;
	}

	private function applyShare(IShare $share, Gallery $gallery, PublicLinkConfiguration $config, bool $creating): void {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$share->setNode($config->startPath === '' ? $root : $root->get($config->startPath));
		$share->setLabel($gallery->getTitle() . ' · ' . $config->name);
		$share->setPermissions(Constants::PERMISSION_READ);
		$share->setHideDownload(!$config->policy->downloadScope->allowsIndividual());
		if ($creating || $config->password !== null) $share->setPassword($config->password === '' ? null : $config->password);
		$share->setExpirationDate($config->expiresAt);
	}

	/** @return array{node: \OCP\Files\Node, label: string, permissions: int, hideDownload: bool, password: ?string, expiration: ?DateTime} */
	private function shareSnapshot(IShare $share): array {
		return [
			'node' => $share->getNode(),
			'label' => (string)$share->getLabel(),
			'permissions' => (int)$share->getPermissions(),
			'hideDownload' => $share->getHideDownload(),
			'password' => $share->getPassword(),
			'expiration' => $share->getExpirationDate(),
		];
	}

	/** @param array{node: \OCP\Files\Node, label: string, permissions: int, hideDownload: bool, password: ?string, expiration: ?DateTime} $snapshot */
	private function compensateShare(IShare $share, array $snapshot, \Throwable $original): void {
		try {
			$share->setNode($snapshot['node']);
			$share->setLabel($snapshot['label']);
			$share->setPermissions($snapshot['permissions']);
			$share->setHideDownload($snapshot['hideDownload']);
			$share->setPassword($snapshot['password']);
			$share->setExpirationDate($snapshot['expiration']);
			$this->shareManager->updateShare($share);
		} catch (\Throwable $compensation) {
			$this->logCompensationFailure('restore a public share', $compensation, $original);
		}
	}

	private function logCompensationFailure(string $action, \Throwable $compensation, \Throwable $original): void {
		$this->logger->error('Failed to ' . $action . ' after app persistence failed', [
			'app' => Application::APP_ID,
			'exception' => $compensation,
			'originalException' => $original,
		]);
	}
}
