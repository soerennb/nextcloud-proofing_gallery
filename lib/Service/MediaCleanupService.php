<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\IDBConnection;

/** Removes mutable application data that belongs to a deleted Nextcloud file id. */
final class MediaCleanupService {
	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
	) {
	}

	/** @return list<int> gallery ids whose projections may have changed */
	public function purge(int $fileId): array {
		$galleryIds = $this->affectedGalleryIds($fileId);
		$versionRows = $this->rowsForFile('proofing_versions', $fileId, ['gallery_id']);
		$videoRows = $this->rowsForFile('proofing_video_deriv', $fileId, ['storage_key', 'poster_key']);

		$this->db->beginTransaction();
		try {
			foreach (['proofing_annotations', 'proofing_feedback', 'proofing_comments', 'proofing_guest_ratings',
				'proofing_media_index', 'proofing_semantic_idx', 'proofing_versions'] as $table) {
				$this->deleteFileRows($table, $fileId);
			}
			$this->deleteFileRows('proofing_media_cull', $fileId);
			$this->deleteFileRows('proofing_video_deriv', $fileId);
			$this->deleteFileRows('proofing_selection_items', $fileId);
			$this->deleteFileRows('proofing_collection_items', $fileId);

			$uploads = $this->db->getQueryBuilder();
			$uploads->update('proofing_uploads')->set('file_id', $uploads->createNamedParameter(null))
				->where($uploads->expr()->eq('file_id', $uploads->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$summaries = $this->db->getQueryBuilder();
			$summaries->delete('proofing_summaries')
				->where($summaries->expr()->eq('cover_file_id', $summaries->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$this->removePresentationReferences($fileId);
			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}

		foreach ($versionRows as $row) $this->deleteVersionFolder((int)$row['gallery_id'], $fileId);
		foreach ($videoRows as $row) {
			$this->deleteAppDataFile('video-derivatives', $row['storage_key'] ?? null);
			$this->deleteAppDataFile('video-derivatives', $row['poster_key'] ?? null);
		}

		return $galleryIds;
	}

	/** @return list<int> */
	private function affectedGalleryIds(int $fileId): array {
		$ids = [];
		foreach (['proofing_feedback', 'proofing_comments', 'proofing_guest_ratings', 'proofing_media_index',
			'proofing_semantic_idx', 'proofing_versions'] as $table) {
			foreach ($this->rowsForFile($table, $fileId, ['gallery_id']) as $row) $ids[] = (int)$row['gallery_id'];
		}
		foreach ($this->rowsForFile('proofing_collection_items', $fileId, ['collection_id', 'source_gallery_id']) as $row) {
			$ids[] = (int)$row['collection_id'];
			$ids[] = (int)$row['source_gallery_id'];
		}
		return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
	}

	/** @param list<string> $columns
	 * @return list<array<string, mixed>>
	 */
	private function rowsForFile(string $table, int $fileId, array $columns): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select(...$columns)->from($table)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeQuery());
	}

	private function deleteFileRows(string $table, int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function removePresentationReferences(int $fileId): void {
		$select = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($select->select('id', 'settings', 'revision')->from('proofing_galleries')->executeQuery());
		foreach ($rows as $row) {
			$settings = json_decode((string)$row['settings'], true);
			if (!is_array($settings) || !is_array($settings['presentation'] ?? null)) continue;
			$presentation = &$settings['presentation'];
			$changed = false;
			foreach (['heroFileId', 'logoFileId'] as $key) {
				if (($presentation[$key] ?? null) === $fileId) {
					$presentation[$key] = null;
					$changed = true;
				}
			}
			foreach ($presentation['story']['sections'] ?? [] as $index => $section) {
				if (!is_array($section['mediaIds'] ?? null)) continue;
				$filtered = array_values(array_filter($section['mediaIds'], static fn (mixed $id): bool => $id !== $fileId));
				if ($filtered !== $section['mediaIds']) {
					$presentation['story']['sections'][$index]['mediaIds'] = $filtered;
					$changed = true;
				}
			}
			unset($presentation);
			if (!$changed) continue;
			$update = $this->db->getQueryBuilder();
			$update->update('proofing_galleries')
				->set('settings', $update->createNamedParameter(json_encode($settings, JSON_THROW_ON_ERROR)))
				->set('revision', $update->createNamedParameter((int)$row['revision'] + 1, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($update->expr()->eq('revision', $update->createNamedParameter((int)$row['revision'], IQueryBuilder::PARAM_INT)));
			if ($update->executeStatement() !== 1) throw new \RuntimeException('Gallery presentation changed during media cleanup');
		}
	}

	private function deleteVersionFolder(int $galleryId, int $fileId): void {
		try {
			$this->appData->getFolder('versions')->getFolder((string)$galleryId)->getFolder((string)$fileId)->delete();
		} catch (\Throwable) {
		}
	}

	private function deleteAppDataFile(string $folder, mixed $name): void {
		if (!is_string($name) || $name === '') return;
		try {
			$parent = $this->appData->getFolder($folder);
			if ($parent->fileExists($name)) $parent->getFile($name)->delete();
		} catch (\Throwable) {
		}
	}
}
