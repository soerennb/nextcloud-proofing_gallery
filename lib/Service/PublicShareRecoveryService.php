<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Exception\PublicShareMissingException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/** Recovery uses native share APIs; app link identities remain stable. */
final class PublicShareRecoveryService {
	/** @var array<int, true> */
	private array $heldLocks = [];

	public function __construct(private IManager $shares, private IDBConnection $db, private ILockingProvider $locks, private IUserManager $users, private LoggerInterface $logger) {
	}

	/** @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function locked(int $galleryId, callable $callback): mixed {
		// Internal share operations may compose while the outer operation owns
		// the lock. Other requests still acquire the native exclusive lock.
		if (isset($this->heldLocks[$galleryId])) return $callback();
		$key = 'proofing-gallery:public-shares:' . $galleryId;
		try {
			$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException $exception) {
			throw new GalleryConflictException('Another share operation is in progress. Please retry.', previous: $exception);
		}
		$this->heldLocks[$galleryId] = true;
		try { return $callback(); }
		finally {
			unset($this->heldLocks[$galleryId]);
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function resolve(Gallery $gallery, ?PublicLink $link, string $token, int $nodeId): IShare {
		$owner = $this->users->get($gallery->getOwnerUid());
		if ($owner === null) throw new \InvalidArgumentException('The gallery owner is unavailable');
		if (!$this->shares->shareApiEnabled() || !$this->shares->shareApiAllowLinks($owner) || $this->shares->sharingDisabledForUser($gallery->getOwnerUid())) {
			throw new PolicyViolationException('public_publishing_disabled', 'Public publishing is disabled by the administrator');
		}
		$id = $this->nativeId($link, $token);
		if ($id === null) throw new PublicShareMissingException();
		try { $share = $this->shares->getShareById('ocinternal:' . $id, onlyValid: false); }
		catch (ShareNotFound) { throw new \InvalidArgumentException('The native share is unavailable'); }
		// SharedBy identifies the gallery owner; ShareOwner may own a reshared source.
		if ($share->getShareType() !== IShare::TYPE_LINK
			|| $share->getSharedBy() !== $gallery->getOwnerUid() || $share->getToken() !== $token || $share->getNodeId() !== $nodeId) {
			throw new \InvalidArgumentException('The native share no longer matches this gallery link');
		}
		if ($share->isExpired()) throw new \InvalidArgumentException('The public share has expired. Revoke it before publishing a new link.');
		// Keep Nextcloud's disabled-owner and other native validity checks.
		return $this->shares->getShareByToken($token);
	}

	private function nativeId(?PublicLink $link, string $token): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('share');
		if ($link?->getCoreShareId() !== null) $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($link->getCoreShareId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		else $qb->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$result = $qb->setMaxResults(1)->executeQuery();
		try { $id = $result->fetchOne(); } finally { $result->closeCursor(); }
		return $id === false ? null : (int)$id;
	}

	public function revoke(Gallery $gallery, PublicLink $link): void {
		$id = $this->nativeId($link, $link->getToken());
		if ($id === null) return;
		$share = $this->shares->getShareById('ocinternal:' . $id, onlyValid: false);
		// SharedBy identifies the gallery owner; ShareOwner may own a reshared source.
		if ($share->getShareType() !== IShare::TYPE_LINK
			|| $share->getSharedBy() !== $gallery->getOwnerUid() || $share->getToken() !== $link->getToken()) {
			throw new \InvalidArgumentException('The native share no longer matches this gallery link');
		}
		$this->shares->deleteShare($share);
	}

	public function create(IShare $share, string $previousToken): IShare {
		if ($this->shares->shareApiLinkDefaultExpireDateEnforced() && $share->getExpirationDate() === null) {
			throw new \InvalidArgumentException('An expiry date is required by the administrator');
		}
		if ($share->getExpirationDate() === null) $share->setNoExpirationDate(true);
		$share = $this->shares->createShare($share);
		$created = clone $share;
		try {
			if ($this->shares->allowCustomTokens() && preg_match('/^[A-Za-z0-9-]{1,32}$/D', $previousToken) === 1
				&& !$this->users->userExists($previousToken) && !$this->tokenExists($previousToken)) {
				$share->setToken($previousToken);
				$share = $this->shares->updateShare($share);
			}
			return $share;
		} catch (\Throwable $exception) {
			$this->discard($created, $exception);
			throw $exception;
		}
	}

	private function tokenExists(string $token): bool {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id')->from('share')->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->setMaxResults(1)->executeQuery();
		try { return $result->fetchOne() !== false; } finally { $result->closeCursor(); }
	}

	/** Returns true when deleted; otherwise remove native access best-effort. */
	public function discard(IShare $share, \Throwable $original): bool {
		try {
			$this->shares->deleteShare($share);
			return true;
		} catch (\Throwable $exception) {
			$this->logger->error('Failed to delete a replacement public share; removing its permissions', ['app' => 'proofing_gallery', 'exception' => $exception, 'originalException' => $original]);
		}
		try {
			$share->setPermissions(0);
			$this->shares->updateShare($share);
		} catch (\Throwable $exception) {
			$this->logger->error('Failed to disable an orphaned replacement public share; reconciliation is required', ['app' => 'proofing_gallery', 'exception' => $exception, 'originalException' => $original]);
		}
		return false;
	}

	public function label(Gallery $gallery, string $name): string {
		return mb_strcut('Proofing Gallery · ' . $gallery->getTitle() . ' · ' . $name, 0, 255, 'UTF-8');
	}
}
