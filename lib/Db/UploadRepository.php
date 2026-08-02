<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class UploadRepository {
	public function __construct(private IDBConnection $db) {
	}

	public function insert(int $galleryId, int $guestId, string $uploadId, string $filename, string $mimeType, int $size, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_uploads')->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT),
			'upload_id' => $qb->createNamedParameter($uploadId),
			'file_id' => $qb->createNamedParameter(null),
			'filename' => $qb->createNamedParameter($filename),
			'mime_type' => $qb->createNamedParameter($mimeType),
			'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter('pending'),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return array<string, mixed>|null */
	public function find(int $galleryId, string $uploadId, ?int $guestId = null): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_uploads')
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		if ($guestId !== null) $qb->andWhere($qb->expr()->eq('guest_id', $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT)));
		$row = QueryResult::row($qb->executeQuery());
		return $row === false ? null : $row;
	}

	public function touch(string $uploadId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))->executeStatement();
	}

	public function finalize(string $uploadId, int $fileId, string $filename, string $mimeType, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')
			->set('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('filename', $qb->createNamedParameter($filename))
			->set('mime_type', $qb->createNamedParameter($mimeType))
			->set('status', $qb->createNamedParameter('awaiting_review'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))->executeStatement();
	}

	public function markResponseReceived(int $galleryId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_galleries')->set('workflow_state', $qb->createNamedParameter('response_received'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('revision', $qb->createFunction('revision + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('workflow_state', $qb->createNamedParameter('completed')))->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function list(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('u.*', 'g.display_name')->from('proofing_uploads', 'u')
			->leftJoin('u', 'proofing_guests', 'g', $qb->expr()->eq('u.guest_id', 'g.id'))
			->where($qb->expr()->eq('u.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('u.created_at', 'DESC')->executeQuery());
	}

	public function moderate(string $uploadId, string $status, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('proofing_uploads')->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('awaiting_review')))
			->executeStatement() === 1;
	}
}
