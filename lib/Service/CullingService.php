<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\MediaCull;
use OCA\ProofingGallery\Db\MediaCullMapper;
use OCA\ProofingGallery\Domain\CullState;
use OCA\ProofingGallery\Dto\CullUpdateCommand;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

final class CullingService {
	use TTransactional;

	public function __construct(
		private MediaCullMapper $culls,
		private FolderService $folders,
		private ITimeFactory $clock,
		private IDBConnection $db,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<MediaCull>
	 */
	public function updateBatch(string $userId, Gallery $gallery, array $items): array {
		$this->assertOwner($userId, $gallery);
		if ($items === [] || count($items) > 200) throw new \InvalidArgumentException('Select between 1 and 200 media items');
		$commands = [];
		$seen = [];
		foreach ($items as $item) {
			$fileId = (int)($item['fileId'] ?? 0);
			if ($fileId < 1 || isset($seen[$fileId])) throw new \InvalidArgumentException('Each media item must be unique');
			$seen[$fileId] = true;
			$commands[] = new CullUpdateCommand(
				$this->folders->resolveMedia($userId, $gallery->getFolderId(), $fileId),
				$fileId,
				(int)($item['expectedRevision'] ?? 0),
				new CullState((int)($item['rating'] ?? 0), (string)($item['color'] ?? 'none'), (string)($item['pick'] ?? 'none'), 'app'),
			);
		}
		return $this->atomic(function () use ($userId, $commands): array {
			$result = [];
			foreach ($commands as $command) $result[] = $this->persist($userId, $command);
			return $result;
		}, $this->db);
	}

	/**
	 * @param list<int> $fileIds
	 * @return array<int, MediaCull>
	 */
	public function forFiles(string $ownerUid, array $fileIds): array {
		$result = [];
		foreach ($this->culls->findMany($ownerUid, $fileIds) as $cull) $result[$cull->getFileId()] = $cull;
		return $result;
	}

	public function synchronize(
		string $userId,
		Gallery $gallery,
		int $fileId,
		int $rating,
		string $color,
		string $pick,
		int $expectedRevision,
		string $source,
		?string $sidecarEtag,
	): MediaCull {
		$this->assertOwner($userId, $gallery);
		return $this->persist($userId, new CullUpdateCommand(
			$this->folders->resolveMedia($userId, $gallery->getFolderId(), $fileId),
			$fileId,
			$expectedRevision,
			new CullState($rating, $color, $pick, $source),
			$sidecarEtag,
		));
	}

	private function persist(string $ownerUid, CullUpdateCommand $command): MediaCull {
		try {
			$cull = $this->culls->findForOwnerFile($ownerUid, $command->fileId);
			if ($command->expectedRevision !== $cull->getRevision()) throw new MetadataConflictException('The culling state changed in another session');
			$this->apply($cull, $command);
			$cull->setUpdatedAt($this->clock->getTime());
			return $this->culls->updateRevision($cull, $command->expectedRevision);
		} catch (DoesNotExistException) {
			if ($command->expectedRevision !== 0) throw new MetadataConflictException('The culling state no longer exists');
			$cull = new MediaCull();
			$cull->setOwnerUid($ownerUid);
			$cull->setFileId($command->fileId);
			$this->apply($cull, $command);
			$cull->setRevision(1);
			$cull->setUpdatedAt($this->clock->getTime());
			return $this->culls->insert($cull);
		}
	}

	private function apply(MediaCull $cull, CullUpdateCommand $command): void {
		$cull->setRating($command->state->rating);
		$cull->setColor($command->state->color);
		$cull->setPickState($command->state->pick);
		$cull->setSource($command->state->source);
		$cull->setSourceEtag($command->file->getEtag());
		$cull->setSidecarEtag($command->sidecarEtag);
	}

	private function assertOwner(string $userId, Gallery $gallery): void {
		if ($gallery->getOwnerUid() !== $userId) throw new AuthorizationException('Only the gallery owner can change culling state');
	}
}
