<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\MediaCull;
use OCA\ProofingGallery\Db\MediaCullMapper;
use OCA\ProofingGallery\Db\MediaIndexMapper;

final class CullingXmpService {
	private const MODES = ['report', 'app', 'xmp', 'merge'];

	public function __construct(
		private MediaIndexMapper $index,
		private MediaCullMapper $culls,
		private FolderService $folders,
		private MediaMetadataService $metadata,
		private CullingService $culling,
	) {
	}

	/**
	 * @param list<int> $fileIds
	 * @param array{rating?: string, color?: string, pick?: string} $fieldChoices
	 * @return array{items: list<array<string, mixed>>, total: int, offset: int, limit: int, nextOffset: ?int, dryRun: bool}
	 */
	public function synchronize(
		string $userId,
		Gallery $gallery,
		string $mode = 'report',
		bool $dryRun = true,
		array $fileIds = [],
		int $limit = 200,
		int $offset = 0,
		array $fieldChoices = [],
	): array {
		if ($gallery->getOwnerUid() !== $userId || $gallery->getSourceType() !== 'folder') throw new \InvalidArgumentException('Only folder gallery owners can synchronize XMP');
		if (!in_array($mode, self::MODES, true)) throw new \InvalidArgumentException('Invalid XMP resolution mode');
		foreach (['rating', 'color', 'pick'] as $field) {
			$choice = $fieldChoices[$field] ?? 'app';
			if (!in_array($choice, ['app', 'xmp'], true)) throw new \InvalidArgumentException('Invalid merge field source');
		}
		$max = $dryRun ? 2000 : 200;
		$limit = max(1, min($max, $limit));
		$offset = max(0, $offset);
		$total = $fileIds === [] ? $this->index->countGallery($gallery->getId()) : count($fileIds);
		$ids = $fileIds === []
			? $this->index->fileIds($gallery->getId(), $limit, $offset)
			: array_slice(array_values(array_unique(array_map('intval', $fileIds))), $offset, $limit);
		$known = $this->culling->forFiles($gallery->getOwnerUid(), $ids);
		$items = [];
		foreach ($ids as $fileId) {
			try {
				$file = $this->folders->resolveMedia($userId, $gallery->getFolderId(), $fileId);
				$appEntity = $known[$fileId] ?? null;
				$app = $this->appState($fileId, $appEntity);
				$xmp = $this->metadata->readCullingSidecar($file);
				$resolved = $this->resolveState($mode, $app, $xmp, $fieldChoices);
				$differences = array_values(array_filter(['rating', 'color', 'pick'], static fn (string $field): bool => $app[$field] !== $xmp[$field]));
				$etagDiverged = $appEntity !== null && $appEntity->getSidecarEtag() !== null && $appEntity->getSidecarEtag() !== $xmp['etag'];
				$action = $mode === 'report' ? 'report' : $mode;
				$resultState = $app;
				$resultEtag = $xmp['etag'];
				$wouldWrite = false;

				if ($mode === 'app') {
					$wouldWrite = !$xmp['exists'] || $differences !== [] || $etagDiverged;
					$write = $this->metadata->writeCullingSidecar($file, $app, $xmp['etag'], $dryRun);
					$resultEtag = $write['etag'];
					if (!$dryRun) $resultState = $this->entityState($this->culling->synchronize($userId, $gallery, $fileId, $app['rating'], $app['color'], $app['pick'], $app['revision'], 'app', $resultEtag));
				} elseif ($mode === 'xmp' && $xmp['exists']) {
					$wouldWrite = $differences !== [] || $etagDiverged;
					$resultState = $resolved;
					if (!$dryRun) $resultState = $this->entityState($this->culling->synchronize($userId, $gallery, $fileId, $xmp['rating'], $xmp['color'], $xmp['pick'], $app['revision'], 'xmp', $xmp['etag']));
				} elseif ($mode === 'merge') {
					$merged = $resolved;
					$wouldWrite = !$xmp['exists'] || array_filter(['rating', 'color', 'pick'], static fn (string $field): bool => $merged[$field] !== $xmp[$field]) !== [];
					$write = $this->metadata->writeCullingSidecar($file, $merged, $xmp['etag'], $dryRun);
					$resultEtag = $write['etag'];
					$resultState = $merged;
					if (!$dryRun) $resultState = $this->entityState($this->culling->synchronize($userId, $gallery, $fileId, $merged['rating'], $merged['color'], $merged['pick'], $app['revision'], 'merge', $resultEtag));
				}

				$items[] = [
					'fileId' => $fileId,
					'name' => $file->getName(),
					'app' => $app,
					'xmp' => $xmp,
					'result' => $resultState,
					'differences' => $differences,
					'conflict' => $etagDiverged,
					'action' => $action,
					'wouldWrite' => $wouldWrite,
				];
			} catch (\Throwable $exception) {
				$items[] = ['fileId' => $fileId, 'error' => $exception->getMessage()];
			}
		}
		$nextOffset = $offset + count($ids) < $total ? $offset + count($ids) : null;
		return compact('items', 'total', 'offset', 'limit', 'nextOffset', 'dryRun');
	}

	/** @return array{fileId: int, rating: int, color: string, pick: string, revision: int} */
	private function appState(int $fileId, ?MediaCull $value): array {
		return $value === null
			? ['fileId' => $fileId, 'rating' => 0, 'color' => 'none', 'pick' => 'none', 'revision' => 0]
			: $this->entityState($value);
	}

	/** @return array{fileId: int, rating: int, color: string, pick: string, revision: int} */
	private function entityState(MediaCull $value): array {
		return [
			'fileId' => $value->getFileId(),
			'rating' => $value->getRating(),
			'color' => $value->getColor(),
			'pick' => $value->getPickState(),
			'revision' => $value->getRevision(),
		];
	}

	/**
	 * @param array{fileId: int, rating: int, color: string, pick: string, revision: int} $app
	 * @param array{exists: bool, rating: int, color: string, pick: string} $xmp
	 * @param array{rating?: string, color?: string, pick?: string} $fieldChoices
	 * @return array{fileId: int, rating: int, color: string, pick: string, revision: int}
	 */
	private function resolveState(string $mode, array $app, array $xmp, array $fieldChoices): array {
		if ($mode === 'xmp' && $xmp['exists']) return [...$app, 'rating' => $xmp['rating'], 'color' => $xmp['color'], 'pick' => $xmp['pick']];
		if ($mode !== 'merge') return $app;
		$result = $app;
		foreach (['rating', 'color', 'pick'] as $field) {
			if (($fieldChoices[$field] ?? 'app') === 'xmp' && $xmp['exists']) $result[$field] = $xmp[$field];
		}
		return $result;
	}
}
