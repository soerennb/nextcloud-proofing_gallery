<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\MediaCull;
use OCA\ProofingGallery\Db\MediaCullMapper;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

final class CullingService {
	private const COLORS = ['none', 'red', 'yellow', 'green', 'blue', 'purple'];
	private const PICKS = ['none', 'pick', 'reject'];

	public function __construct(
		private MediaCullMapper $culls,
		private FolderService $folders,
		private ITimeFactory $clock,
	) {
	}

	/** @param list<array<string, mixed>> $items
	 * @return list<MediaCull>
	 */
	public function updateBatch(string $userId, Gallery $gallery, array $items): array {
		if ($gallery->getOwnerUid() !== $userId) throw new AuthorizationException('Only the gallery owner can change culling state');
		if ($items === [] || count($items) > 200) throw new \InvalidArgumentException('Select between 1 and 200 media items');
		$result = [];
		$seen = [];
		foreach ($items as $item) {
			$fileId = (int)($item['fileId'] ?? 0);
			if ($fileId < 1 || isset($seen[$fileId])) throw new \InvalidArgumentException('Each media item must be unique');
			$seen[$fileId] = true;
			$rating = (int)($item['rating'] ?? 0);
			$color = (string)($item['color'] ?? 'none');
			$pick = (string)($item['pick'] ?? 'none');
			$expectedRevision = (int)($item['expectedRevision'] ?? 0);
			if ($rating < 0 || $rating > 5 || !in_array($color, self::COLORS, true) || !in_array($pick, self::PICKS, true)) {
				throw new \InvalidArgumentException('Invalid culling value');
			}
			$file = $this->folders->resolveMedia($userId, $gallery->getFolderId(), $fileId);
			try {
				$cull = $this->culls->findForOwnerFile($userId, $fileId);
				if ($expectedRevision !== $cull->getRevision()) throw new MetadataConflictException('The culling state changed in another session');
				$cull->setRating($rating);
				$cull->setColor($color);
				$cull->setPickState($pick);
				$cull->setSource('app');
				$cull->setSourceEtag($file->getEtag());
				$cull->setUpdatedAt($this->clock->getTime());
				$result[] = $this->culls->updateRevision($cull, $expectedRevision);
			} catch (DoesNotExistException) {
				if ($expectedRevision !== 0) throw new MetadataConflictException('The culling state no longer exists');
				$cull = new MediaCull();
				$cull->setOwnerUid($userId);
				$cull->setFileId($fileId);
				$cull->setRating($rating);
				$cull->setColor($color);
				$cull->setPickState($pick);
				$cull->setSource('app');
				$cull->setRevision(1);
				$cull->setSourceEtag($file->getEtag());
				$cull->setUpdatedAt($this->clock->getTime());
				$result[] = $this->culls->insert($cull);
			}
		}
		return $result;
	}

	/** @param list<int> $fileIds
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
		if ($gallery->getOwnerUid() !== $userId) throw new AuthorizationException('Only the gallery owner can synchronize culling state');
		if ($rating < 0 || $rating > 5 || !in_array($color, self::COLORS, true) || !in_array($pick, self::PICKS, true)
			|| !in_array($source, ['app', 'xmp', 'merge'], true)) throw new \InvalidArgumentException('Invalid culling value');
		$file = $this->folders->resolveMedia($userId, $gallery->getFolderId(), $fileId);
		try {
			$cull = $this->culls->findForOwnerFile($userId, $fileId);
			if ($expectedRevision !== $cull->getRevision()) throw new MetadataConflictException('The culling state changed in another session');
			$cull->setRating($rating);
			$cull->setColor($color);
			$cull->setPickState($pick);
			$cull->setSource($source);
			$cull->setSourceEtag($file->getEtag());
			$cull->setSidecarEtag($sidecarEtag);
			$cull->setUpdatedAt($this->clock->getTime());
			return $this->culls->updateRevision($cull, $expectedRevision);
		} catch (DoesNotExistException) {
			if ($expectedRevision !== 0) throw new MetadataConflictException('The culling state no longer exists');
			$cull = new MediaCull();
			$cull->setOwnerUid($userId);
			$cull->setFileId($fileId);
			$cull->setRating($rating);
			$cull->setColor($color);
			$cull->setPickState($pick);
			$cull->setSource($source);
			$cull->setRevision(1);
			$cull->setSourceEtag($file->getEtag());
			$cull->setSidecarEtag($sidecarEtag);
			$cull->setUpdatedAt($this->clock->getTime());
			return $this->culls->insert($cull);
		}
	}
}
