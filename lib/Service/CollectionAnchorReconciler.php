<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;

final class CollectionAnchorReconciler {
	private const USER_BATCH_SIZE = 100;
	private const ANCHOR_BATCH_SIZE = 100;
	private const MINIMUM_AGE_SECONDS = 86400;
	private const CURSOR_KEY = 'collectionAnchorReconcileUserOffset';

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $users,
		private CollectionAnchorReferences $references,
		private IConfig $config,
		private ITimeFactory $clock,
	) {
	}

	/**
	 * @return array{
	 *   dryRun: int,
	 *   users: int,
	 *   anchors: int,
	 *   candidates: int,
	 *   deleted: int,
	 *   referenced: int,
	 *   recent: int,
	 *   nonEmpty: int,
	 *   ignored: int,
	 *   inaccessibleUsers: int
	 * }
	 */
	public function reconcile(bool $dryRun = true): array {
		$offset = max(0, (int)$this->config->getAppValue(Application::APP_ID, self::CURSOR_KEY, '0'));
		$users = $this->users->search('', self::USER_BATCH_SIZE, $offset);
		$result = [
			'dryRun' => $dryRun ? 1 : 0,
			'users' => 0,
			'anchors' => 0,
			'candidates' => 0,
			'deleted' => 0,
			'referenced' => 0,
			'recent' => 0,
			'nonEmpty' => 0,
			'ignored' => 0,
			'inaccessibleUsers' => 0,
		];
		$cutoff = $this->clock->getTime() - self::MINIMUM_AGE_SECONDS;

		foreach ($users as $user) {
			if ($result['anchors'] >= self::ANCHOR_BATCH_SIZE) {
				break;
			}
			$result['users']++;
			try {
				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				if (!$userFolder->nodeExists('.proofing-gallery/collections')) {
					continue;
				}
				$collections = $userFolder->get('.proofing-gallery/collections');
				if (!$collections instanceof Folder) {
					$result['ignored']++;
					continue;
				}
			} catch (\Throwable) {
				$result['inaccessibleUsers']++;
				continue;
			}
			foreach ($collections->getDirectoryListing() as $anchor) {
				if ($result['anchors'] >= self::ANCHOR_BATCH_SIZE) {
					break;
				}
				$result['anchors']++;
				if (!$anchor instanceof Folder || preg_match('/^[a-f0-9]{32}$/', $anchor->getName()) !== 1) {
					$result['ignored']++;
					continue;
				}
				if ($this->references->isReferenced($anchor->getId())) {
					$result['referenced']++;
					continue;
				}
				if ($anchor->getMTime() > $cutoff) {
					$result['recent']++;
					continue;
				}
				if ($anchor->getDirectoryListing() !== []) {
					$result['nonEmpty']++;
					continue;
				}
				$result['candidates']++;
				if (!$dryRun) {
					$anchor->delete();
					$result['deleted']++;
				}
			}
		}

		if (!$dryRun) {
			$processedAllReturnedUsers = $result['users'] === count($users);
			$nextOffset = $processedAllReturnedUsers && count($users) < self::USER_BATCH_SIZE
				? 0
				: $offset + $result['users'];
			$this->config->setAppValue(Application::APP_ID, self::CURSOR_KEY, (string)$nextOffset);
		}

		return $result;
	}
}
