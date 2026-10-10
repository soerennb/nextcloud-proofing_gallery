<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Db\PublicLinkRootRepository;
use OCA\ProofingGallery\Dto\SourceRebindResult;
use OCA\ProofingGallery\Dto\SourceScopeMapping;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/** Rebind every usable link without expanding its relative folder scope. */
final class GallerySourceRebindingService {
	use TTransactional;

	public function __construct(
		private GalleryMapper $galleries,
		private PublicLinkMapper $links,
		private PublicLinkRootRepository $rootRows,
		private FolderService $folders,
		private SourceScopeMapper $mapper,
		private PublicLinkAnchorService $anchors,
		private PrimaryPublicLinkSynchronizer $primary,
		private PublicShareRecoveryService $recovery,
		private IManager $shares,
		private IDBConnection $db,
		private ITimeFactory $clock,
		private LifecycleScheduleService $schedule,
		private MediaSummaryService $summaries,
		private GalleryMediaCountInvalidator $counts,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
	}

	public function rebind(Gallery $gallery, int $folderId): SourceRebindResult {
		return $this->recovery->locked((int)$gallery->getId(), fn (): SourceRebindResult => $this->rebindLocked($this->galleries->find((int)$gallery->getId()), $folderId));
	}

	private function rebindLocked(Gallery $gallery, int $folderId): SourceRebindResult {
		if ($gallery->getDeliveryMode() === 'event' && ($gallery->getShareToken() !== null || $this->links->countUsableForGallery((int)$gallery->getId()) > 0)) {
			throw new \InvalidArgumentException('Revoke event links before changing the source folder');
		}
		$newRoot = $this->folders->resolveFolder($gallery->getOwnerUid(), $folderId);
		try { $oldRoot = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId()); }
		catch (FolderAccessException) { $oldRoot = null; }
		if ($gallery->getShareToken() !== null) {
			try { $this->links->findPrimary((int)$gallery->getId()); }
			catch (DoesNotExistException) { $this->primary->ensurePrimary($gallery); }
		}
		$plans = [];
		foreach ($this->links->findUsableForGallery((int)$gallery->getId()) as $link) {
			$share = $this->recovery->nativeShare($gallery, $link, $link->getToken());
			$oldNode = null;
			try { $oldNode = $share?->getNode(); } catch (Throwable) { /* Removed sources have no node to restore. */ }
			if ($share !== null && $link->getScopeAnchorId() !== null && $share->getNodeId() !== $link->getScopeAnchorId()) {
				throw new \InvalidArgumentException('The native share no longer matches its scope anchor');
			}
			if ($oldRoot !== null && $oldNode !== null && $link->getScopeMode() === 'legacy' && $link->allowedRootList() === []) {
				if (($link->getStartPath() === '' && $oldNode->getId() !== $oldRoot->getId()) || ($link->getStartPath() !== '' && !$oldRoot->isSubNode($oldNode))) {
					throw new \InvalidArgumentException('The native share no longer matches the source folder');
				}
			}
			$rows = $this->rootRows->findForLink((int)$link->getId());
			if ($rows === [] && $link->getScopeMode() === 'legacy' && $share !== null) {
				$rows = [['folderId' => (int)$share->getNodeId(), 'pathSnapshot' => $link->getStartPath(), 'role' => 'shared']];
			}
			$mapping = $this->mapper->map($link, $oldRoot, $newRoot, $rows);
			if (($mapping->multiRoot || $mapping->unavailable()) && $link->getScopeAnchorId() !== null) {
				$this->assertEmptyAnchor($this->anchors->resolve($gallery->getOwnerUid(), $link->getScopeAnchorId()));
			}
			$plans[] = ['link' => $link, 'share' => $share, 'node' => $oldNode, 'permissions' => $share?->getPermissions() ?? 0, 'mapping' => $mapping];
		}
		$changed = []; $createdAnchors = []; $missing = []; $suspended = [];
		try {
			$result = $this->atomic(function () use ($gallery, $folderId, $plans, &$changed, &$createdAnchors, &$missing, &$suspended): Gallery {
				foreach ($plans as $plan) {
					$link = $plan['link']; $mapping = $plan['mapping']; $share = $plan['share'];
					foreach ($mapping->missing as $path) $missing[] = ['linkId' => (int)$link->getId(), 'linkName' => $link->getName(), 'path' => $path];
					$target = $mapping->target;
					if ($mapping->multiRoot || $mapping->unavailable()) {
						$target = $this->anchor($gallery, $link, $createdAnchors);
					}
					if ($share !== null && $target !== null) {
						// Register before update: a native API can throw after writing.
						$changed[] = ['link' => clone $link, 'share' => $share, 'node' => $plan['node'], 'permissions' => $plan['permissions']];
						$share->setNode($target);
						if ($mapping->unavailable() || $link->getStatus() === 'suspended') $share->setPermissions(0);
						$this->shares->updateShare($share, onlyValid: false);
					}
					$this->applyMapping($link, $mapping);
					if ($link->getScopeMode() === 'empty') $suspended[] = (int)$link->getId();
					$link->setUpdatedAt($this->clock->getTime());
					$this->links->update($link);
				}
				$gallery->setFolderId($folderId);
				$settings = \OCA\ProofingGallery\Dto\GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
				if ($settings->presentation->coverFileId !== null) {
					try { $this->folders->resolveMedia($gallery->getOwnerUid(), $folderId, $settings->presentation->coverFileId); }
					catch (FolderAccessException) {
						$settings = \OCA\ProofingGallery\Dto\GallerySettings::merge($settings, ['presentation' => ['coverFileId' => null]]);
						$gallery->setSettings(json_encode($settings, JSON_THROW_ON_ERROR));
					}
				}
				$gallery->setUpdatedAt($this->clock->getTime());
				$gallery->setRevision($gallery->getRevision() + 1);
				$this->schedule->project($gallery, $this->clock->getTime());
				return $this->galleries->update($gallery);
			}, $this->db);
		} catch (Throwable $exception) {
			$this->compensate($gallery, $changed, $exception);
			foreach ($createdAnchors as $anchor) {
				try { if (!$this->rootRows->isAnchorReferenced((int)$anchor->getId())) $this->anchors->delete($anchor); }
				catch (Throwable $cleanup) { $this->logger->error('Could not clean up source-rebind anchor', ['exception' => $cleanup]); }
			}
			throw $exception;
		}
		$this->summaries->invalidate((int)$result->getId());
		$this->counts->invalidate((int)$result->getId(), true);
		// Database/native changes are committed. Queue failures must not restore the old source.
		try { $this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => $result->getId()]); }
		catch (Throwable $exception) { $this->logger->error('Could not queue media index after source rebind', ['exception' => $exception]); }
		return new SourceRebindResult($result, $missing, $suspended);
	}

	/** @param list<Folder> $created */
	private function anchor(Gallery $gallery, PublicLink $link, array &$created): Folder {
		if ($link->getScopeAnchorId() === null) {
			$anchor = $this->anchors->create($gallery->getOwnerUid());
			$created[] = $anchor;
			$link->setScopeAnchorId((int)$anchor->getId());
		} else $anchor = $this->anchors->resolve($gallery->getOwnerUid(), $link->getScopeAnchorId());
		$this->assertEmptyAnchor($anchor);
		return $anchor;
	}

	private function assertEmptyAnchor(Folder $anchor): void {
		if ($anchor->getDirectoryListing() !== []) throw new \InvalidArgumentException('The public link scope anchor must be empty');
	}

	private function applyMapping(PublicLink $link, SourceScopeMapping $mapping): void {
		if ($mapping->unavailable()) {
			$link->setStatus('suspended');
			$link->setScopeMode('empty');
			// Retain paths and root snapshots for explicit repair; empty mode grants nothing.
			return;
		}
		if ($mapping->multiRoot) {
			if ($link->getScopeMode() !== 'empty') $link->setScopeMode('nodes');
			$link->setAllowedRootList(array_column($mapping->roots, 'path'));
			$this->rootRows->replace((int)$link->getId(), $mapping->roots);
		} else $link->setStartPath($mapping->roots[0]['path']);
	}

	/** @param list<array{link: PublicLink, share: IShare, node: ?Node, permissions: int}> $changed */
	private function compensate(Gallery $gallery, array $changed, Throwable $original): void {
		foreach (array_reverse($changed) as $snapshot) {
			try {
				if ($snapshot['node'] === null) throw new \RuntimeException('The old share node no longer exists');
				$snapshot['share']->setNode($snapshot['node']);
				$snapshot['share']->setPermissions($snapshot['permissions']);
				$this->shares->updateShare($snapshot['share'], onlyValid: false);
			} catch (Throwable $exception) {
				$this->recovery->discard($snapshot['share'], $original);
				try {
					$link = $this->links->find((int)$snapshot['link']->getId());
					if ($link->getScopeAnchorId() === null) {
						$link->setScopeAnchorId((int)$this->anchors->create($gallery->getOwnerUid())->getId());
					}
					$link->setStatus('suspended');
					$link->setScopeMode('empty');
					$this->links->update($link);
				} catch (Throwable $persist) { $this->logger->error('Could not record disabled source-rebind share', ['exception' => $persist, 'galleryId' => $gallery->getId()]); }
				$this->logger->error('Source-rebind rollback failed; public share was disabled', ['exception' => $exception, 'galleryId' => $gallery->getId()]);
			}
		}
	}
}
