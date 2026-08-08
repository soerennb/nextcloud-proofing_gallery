<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\ContextChat;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\ManagerMapper;
use OCA\ProofingGallery\Service\IntegrationReadService;
use OCP\App\IAppManager;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\IGroupManager;
use OCP\IServerContainer;

final class GalleryContentSyncService {
	public const PROVIDER_ID = 'galleries';

	public function __construct(
		private GalleryMapper $galleries,
		private ManagerMapper $managers,
		private IGroupManager $groups,
		private IntegrationReadService $read,
		private IAppManager $apps,
		private IServerContainer $container,
	) {
	}

	public function initialImport(): void {
		$this->initialImportBatch(0);
	}

	public function initialImportBatch(int $afterId, int $limit = 100): ?int {
		$manager = $this->manager();
		if ($manager === null) return null;
		$galleries = $this->galleries->findContextCandidatesAfterId($afterId, $limit);
		if ($galleries === []) return null;
		$manager->submitContent(Application::APP_ID, array_map(fn (Gallery $gallery): ContentItem => $this->item($gallery), $galleries));
		$last = end($galleries);
		return count($galleries) === $limit ? (int)$last->getId() : null;
	}

	public function sync(int $galleryId): void {
		$manager = $this->manager();
		if ($manager === null) return;
		try {
			$gallery = $this->galleries->find($galleryId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			$manager->deleteContent(Application::APP_ID, self::PROVIDER_ID, [(string)$galleryId]);
			return;
		}
		if ($gallery->getStatus() === 'archived') {
			$manager->deleteContent(Application::APP_ID, self::PROVIDER_ID, [(string)$galleryId]);
			return;
		}
		$manager->submitContent(Application::APP_ID, [$this->item($gallery)]);
		$manager->updateAccessDeclarative(Application::APP_ID, self::PROVIDER_ID, (string)$galleryId, $this->users($gallery));
	}

	private function item(Gallery $gallery): ContentItem {
		$summary = $this->read->galleryById($gallery->getOwnerUid(), (int)$gallery->getId());
		$content = implode("\n", [
			'Title: ' . $gallery->getTitle(),
			'Purpose: ' . $gallery->getPurpose(),
			'Status: ' . $gallery->getStatus(),
			'Workflow state: ' . $gallery->getWorkflowState(),
			'Source type: ' . $gallery->getSourceType(),
			'Photo count: ' . (int)$summary['mediaSummary']['total'],
		]);
		return new ContentItem(
			(string)$gallery->getId(),
			self::PROVIDER_ID,
			$gallery->getTitle(),
			$content,
			'Customer gallery',
			(new \DateTime())->setTimestamp($gallery->getUpdatedAt()),
			$this->users($gallery),
		);
	}

	/** @return list<string> */
	private function users(Gallery $gallery): array {
		$users = [$gallery->getOwnerUid() => true];
		foreach ($this->managers->findByGallery((int)$gallery->getId()) as $manager) {
			if ($manager->getPrincipalType() === 'user') {
				$users[$manager->getUserUid()] = true;
				continue;
			}
			$group = $this->groups->get($manager->getUserUid());
			if ($group === null) continue;
			foreach ($group->getUsers() as $user) $users[$user->getUID()] = true;
		}
		return array_keys($users);
	}

	private function manager(): ?IContentManager {
		if (!$this->apps->isInstalled('context_chat')) return null;
		$manager = $this->container->get(IContentManager::class);
		return $manager instanceof IContentManager && $manager->isContextChatAvailable() ? $manager : null;
	}
}
