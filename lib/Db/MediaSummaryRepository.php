<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class MediaSummaryRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function find(int $galleryId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_summaries')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	public function delete(int $galleryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_summaries')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @param array{total: int, coverFileId: ?int, coverMimeType: ?string} $summary */
	public function upsert(int $galleryId, int $folderId, string $etag, array $summary, int $now, bool $exists): void {
		if ($exists) {
			$this->update($galleryId, $folderId, $etag, $summary, $now);
			return;
		}
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('proofing_summaries')->values($this->values($qb, $galleryId, $folderId, $etag, $summary, $now))
				->executeStatement();
		} catch (UniqueConstraintViolationException) {
			$this->update($galleryId, $folderId, $etag, $summary, $now);
		}
	}

	/** @param array{total: int, coverFileId: ?int, coverMimeType: ?string} $summary */
	private function update(int $galleryId, int $folderId, string $etag, array $summary, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_summaries')
			->set('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
			->set('folder_etag', $qb->createNamedParameter($etag))
			->set('media_total', $qb->createNamedParameter($summary['total'], IQueryBuilder::PARAM_INT))
			->set('cover_file_id', $qb->createNamedParameter($summary['coverFileId'], IQueryBuilder::PARAM_INT))
			->set('cover_mime_type', $qb->createNamedParameter($summary['coverMimeType']))
			->set('scanned_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @param array{total: int, coverFileId: ?int, coverMimeType: ?string} $summary
	 * @return array<string, mixed>
	 */
	private function values(IQueryBuilder $qb, int $galleryId, int $folderId, string $etag, array $summary, int $now): array {
		return [
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'folder_id' => $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT),
			'folder_etag' => $qb->createNamedParameter($etag),
			'media_total' => $qb->createNamedParameter($summary['total'], IQueryBuilder::PARAM_INT),
			'cover_file_id' => $qb->createNamedParameter($summary['coverFileId'], IQueryBuilder::PARAM_INT),
			'cover_mime_type' => $qb->createNamedParameter($summary['coverMimeType']),
			'scanned_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		];
	}
}
