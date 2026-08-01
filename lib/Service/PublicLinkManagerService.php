<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use DateTime;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;

final class PublicLinkManagerService {
	public function __construct(
		private PublicLinkMapper $links,
		private GalleryMapper $galleries,
		private PublicLinkPolicyService $policies,
		private PublicLinkScopeService $scopes,
		private PublicLinkService $primaryLinks,
		private FolderService $folders,
		private IManager $shareManager,
		private ITimeFactory $clock,
		private IURLGenerator $urls,
		private ShareAuditService $audit,
	) {
	}

	/** @return array{items: list<array<string, mixed>>, presets: array<string, array<string, bool|string>>} */
	public function list(Gallery $gallery): array {
		return [
			'items' => array_map(fn (PublicLink $link): array => $this->present($link), $this->primaryLinks->list($gallery)),
			'presets' => $this->policies->presets(),
		];
	}

	/** @param array<string, mixed> $policy */
	public function create(
		Gallery $gallery,
		string $name,
		array $policy,
		string $startPath,
		string $viewMode,
		int $groupDepth,
		int $minOwnerRating,
		?string $publicLocale,
		?string $password,
		?string $expiresAt,
	): array {
		$this->primaryLinks->assertBelowLimit($gallery);
		$config = $this->validate($gallery, $name, $policy, $startPath, $viewMode, $groupDepth, $minOwnerRating, $publicLocale);
		$share = $this->newShare($gallery);
		$this->applyShare($share, $gallery, $config['name'], $config['policy'], $config['startPath'], $password, $expiresAt, true);
		$share = $this->shareManager->createShare($share);
		$now = $this->clock->getTime();
		$link = new PublicLink();
		$link->setGalleryId($gallery->getId());
		$link->setCoreShareId((int)$share->getId());
		$link->setToken($share->getToken());
		$link->setName($config['name']);
		$link->setStatus('active');
		$link->setIsPrimary(false);
		$link->setPolicy(json_encode($config['policy'], JSON_THROW_ON_ERROR));
		$link->setStartPath($config['startPath']);
		$link->setViewMode($config['viewMode']);
		$link->setGroupDepth($config['groupDepth']);
		$link->setMinOwnerRating($config['minOwnerRating']);
		$link->setPublicLocale($config['publicLocale']);
		$link->setCreatedAt($now);
		$link->setUpdatedAt($now);
		try {
			return $this->present($this->links->insert($link));
		} catch (\Throwable $exception) {
			try { $this->shareManager->deleteShare($share); } catch (\Throwable) {}
			throw $exception;
		}
	}

	/** @param array<string, mixed> $policy */
	public function update(
		Gallery $gallery,
		int $linkId,
		string $name,
		array $policy,
		string $startPath,
		string $viewMode,
		int $groupDepth,
		int $minOwnerRating,
		?string $publicLocale,
		?string $password,
		?string $expiresAt,
	): array {
		$link = $this->owned($gallery, $linkId);
		if ($link->getStatus() !== 'active') throw new \InvalidArgumentException('Revoked links cannot be edited');
		$config = $this->validate($gallery, $name, $policy, $startPath, $viewMode, $groupDepth, $minOwnerRating, $publicLocale);
		$share = $this->shareManager->getShareByToken($link->getToken());
		$this->applyShare($share, $gallery, $config['name'], $config['policy'], $config['startPath'], $password, $expiresAt, false);
		$this->shareManager->updateShare($share);
		$link->setName($config['name']);
		$link->setPolicy(json_encode($config['policy'], JSON_THROW_ON_ERROR));
		$link->setStartPath($config['startPath']);
		$link->setViewMode($config['viewMode']);
		$link->setGroupDepth($config['groupDepth']);
		$link->setMinOwnerRating($config['minOwnerRating']);
		$link->setPublicLocale($config['publicLocale']);
		$link->setUpdatedAt($this->clock->getTime());
		return $this->present($this->links->update($link));
	}

	public function makePrimary(Gallery $gallery, int $linkId): array {
		$link = $this->owned($gallery, $linkId);
		if ($link->getStatus() !== 'active') throw new \InvalidArgumentException('Only active links can be primary');
		$this->links->clearPrimary($gallery->getId());
		$link->setIsPrimary(true);
		$link->setUpdatedAt($this->clock->getTime());
		$link = $this->links->update($link);
		$gallery->setShareToken($link->getToken());
		$gallery->setUpdatedAt($this->clock->getTime());
		$gallery->setRevision($gallery->getRevision() + 1);
		$this->galleries->update($gallery);
		return $this->present($link);
	}

	public function revoke(Gallery $gallery, int $linkId, string $actorUid): array {
		$link = $this->owned($gallery, $linkId);
		if ($link->getIsPrimary()) throw new \InvalidArgumentException('Use the legacy revoke action for the primary link');
		if ($link->getStatus() === 'active') {
			try { $this->shareManager->deleteShare($this->shareManager->getShareByToken($link->getToken())); } catch (\Throwable) {}
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

	/** @param array<string, mixed> $policy @return array{name: string, policy: array<string, bool|string>, startPath: string, viewMode: string, groupDepth: int, minOwnerRating: int, publicLocale: ?string} */
	private function validate(Gallery $gallery, string $name, array $policy, string $startPath, string $viewMode, int $groupDepth, int $minOwnerRating, ?string $publicLocale): array {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Link name must contain 1 to 120 characters');
		$policy = $this->policies->validate($policy);
		if (!$policy['view']) throw new \InvalidArgumentException('Public links must allow viewing');
		$startPath = $this->scopes->normalize($startPath);
		if (!in_array($viewMode, ['folder', 'recursive'], true) || $groupDepth < 0 || $groupDepth > 8 || $minOwnerRating < 0 || $minOwnerRating > 5) throw new \InvalidArgumentException('Invalid public link view');
		if ($publicLocale !== null && !in_array($publicLocale, ['en', 'de'], true)) throw new \InvalidArgumentException('Invalid public locale');
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		if ($startPath !== '') {
			$node = $root->get($startPath);
			if (!$node instanceof Folder || !$root->isSubNode($node)) throw new \InvalidArgumentException('Public link start folder not found');
		}
		return compact('name', 'policy', 'startPath', 'viewMode', 'groupDepth', 'minOwnerRating', 'publicLocale');
	}

	private function newShare(Gallery $gallery): IShare {
		$share = $this->shareManager->newShare();
		$share->setShareType(IShare::TYPE_LINK);
		$share->setNode($this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()));
		$share->setSharedBy($gallery->getOwnerUid());
		$share->setShareOwner($gallery->getOwnerUid());
		return $share;
	}

	/** @param array<string, bool|string> $policy */
	private function applyShare(IShare $share, Gallery $gallery, string $name, array $policy, string $startPath, ?string $password, ?string $expiresAt, bool $creating): void {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$share->setNode($startPath === '' ? $root : $root->get($startPath));
		$share->setLabel($gallery->getTitle() . ' · ' . $name);
		$share->setPermissions(Constants::PERMISSION_READ);
		$share->setHideDownload(!in_array($policy['downloadScope'], ['individual', 'all'], true));
		if ($creating || $password !== null) $share->setPassword($password === '' ? null : $password);
		$share->setExpirationDate($this->expirationDate($expiresAt));
	}

	private function expirationDate(?string $value): ?DateTime {
		if ($value === null || $value === '') return null;
		$date = DateTime::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Expiration date must use YYYY-MM-DD');
		return $date;
	}
}
