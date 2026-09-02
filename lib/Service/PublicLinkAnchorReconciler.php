<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;

final class PublicLinkAnchorReconciler {
	private const USER_BATCH_SIZE = 100;
	private const ANCHOR_BATCH_SIZE = 100;
	private const MINIMUM_AGE_SECONDS = 86400;
	private const CURSOR_KEY = 'publicLinkAnchorReconcileUserOffset';

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $users,
		private PublicLinkAnchorReferences $references,
		private IConfig $config,
		private ITimeFactory $clock,
	) {
	}

	/** @return array{users: int, anchors: int, deleted: int, referenced: int, retained: int} */
	public function reconcile(): array {
		$offset = max(0, (int)$this->config->getAppValue(Application::APP_ID, self::CURSOR_KEY, '0'));
		$users = $this->users->search('', self::USER_BATCH_SIZE, $offset);
		$result = ['users' => 0, 'anchors' => 0, 'deleted' => 0, 'referenced' => 0, 'retained' => 0];
		$cutoff = $this->clock->getTime() - self::MINIMUM_AGE_SECONDS;
		foreach ($users as $user) {
			if ($result['anchors'] >= self::ANCHOR_BATCH_SIZE) break;
			$result['users']++;
			try {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				if (!$userFolder->nodeExists('.proofing-gallery/public-link-anchors')) continue;
				$folder = $userFolder->get('.proofing-gallery/public-link-anchors');
				if (!$folder instanceof Folder) continue;
				foreach ($folder->getDirectoryListing() as $anchor) {
					if ($result['anchors'] >= self::ANCHOR_BATCH_SIZE) break;
					$result['anchors']++;
					if (!$anchor instanceof Folder || $anchor->getMTime() > $cutoff || $anchor->getDirectoryListing() !== []) {
						$result['retained']++; continue;
					}
					if ($this->references->isAnchorReferenced($anchor->getId())) { $result['referenced']++; continue; }
					$anchor->delete();
					$result['deleted']++;
				}
			} catch (\Throwable) {
				$result['retained']++;
			}
		}
		$nextOffset = $result['users'] === count($users) && count($users) < self::USER_BATCH_SIZE ? 0 : $offset + $result['users'];
		$this->config->setAppValue(Application::APP_ID, self::CURSOR_KEY, (string)$nextOffset);
		return $result;
	}
}
