<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\RetentionRepository;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/** A tag-only handoff boundary. This service never deletes a Nextcloud node. */
final class RetentionHandoffService {
	private const OBJECT_TYPE = 'files';

	public function __construct(
		private RetentionRepository $repository,
		private PolicyService $policies,
		private ISystemTagManager $tags,
		private ISystemTagObjectMapper $objects,
		private ITimeFactory $clock,
	) {
	}

	/** @return array{enabled:bool,systemTagId:string,availableTags:list<array{id:string,name:string}>} */
	public function configuration(): array {
		$config = $this->policies->retentionSettings();
		$available = array_map(static fn ($tag): array => ['id' => $tag->getId(), 'name' => $tag->getName()], $this->tags->getAllTags());
		usort($available, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
		return ['enabled' => $config['enabled'], 'systemTagId' => $config['systemTagId'], 'availableTags' => $available];
	}

	/** @return array<string, mixed> */
	public function status(Gallery $gallery): array {
		$config = $this->policies->retentionSettings();
		$latest = $this->repository->latest((int)$gallery->getId());
		$latestSuccessful = $this->repository->latestSuccessful((int)$gallery->getId());
		return [
			'available' => $config['enabled'] && $config['systemTagId'] !== '' && $gallery->getSourceType() === 'folder',
			'configuredTagId' => $config['systemTagId'],
			'assigned' => $latestSuccessful !== null && $latestSuccessful['action'] === 'assign',
			'lastAction' => $latest === null ? null : ['action' => $latest['action'], 'outcome' => $latest['outcome'], 'errorCode' => $latest['error_code'], 'createdAt' => (int)$latest['created_at']],
		];
	}

	/** @return array<string, mixed> */
	public function assign(Gallery $gallery, string $actor): array {
		$rule = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR))->lifecycle;
		$config = $this->policies->retentionSettings();
		if (!$rule->retentionHandoff) throw new \InvalidArgumentException('Retention handoff is not enabled for this gallery');
		if (!$config['enabled'] || $config['systemTagId'] === '' || $gallery->getSourceType() !== 'folder') throw new \InvalidArgumentException('Retention handoff is unavailable');
		if ($this->status($gallery)['assigned']) return $this->status($gallery);
		$this->mutate($gallery, $config['systemTagId'], 'assign', $actor);
		return $this->status($gallery);
	}

	/** @return array<string, mixed> */
	public function remove(Gallery $gallery, string $actor): array {
		$latest = $this->repository->latestSuccessful((int)$gallery->getId());
		if ($latest === null || $latest['action'] !== 'assign') return $this->status($gallery);
		$this->mutate($gallery, (string)$latest['tag_id'], 'remove', $actor);
		return $this->status($gallery);
	}

	public function assignOnArchive(Gallery $gallery, string $actor): void {
		try { $this->assign($gallery, $actor); } catch (\Throwable) {
			// Disabled and non-opted-in galleries need no audit noise.
		}
	}

	private function mutate(Gallery $gallery, string $tagId, string $action, string $actor): void {
		$error = null;
		try {
			$this->tags->getTagsByIds([$tagId]);
			if ($action === 'assign') $this->objects->assignTags((string)$gallery->getFolderId(), self::OBJECT_TYPE, [$tagId]);
			else $this->objects->unassignTags((string)$gallery->getFolderId(), self::OBJECT_TYPE, [$tagId]);
		} catch (\Throwable $exception) {
			$error = $exception instanceof \OCP\SystemTag\TagNotFoundException ? 'tag_not_found' : 'tag_operation_failed';
		}
		$this->repository->record((int)$gallery->getId(), $gallery->getFolderId(), $tagId, $action, $actor, $error === null ? 'success' : 'failed', $error, $this->clock->getTime());
		if ($error !== null) throw new \InvalidArgumentException($error);
	}
}
