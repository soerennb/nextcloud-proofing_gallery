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


	public function finalizePrivate(string $uploadId, string $filename, string $mimeType, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$changed = $qb->update('proofing_uploads')->set('private_key', $qb->createNamedParameter($uploadId))
			->set('file_id', $qb->createNamedParameter(null))->set('filename', $qb->createNamedParameter($filename))
			->set('mime_type', $qb->createNamedParameter($mimeType))->set('status', $qb->createNamedParameter('awaiting_review'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))->executeStatement();
		if ($changed !== 1) throw new \RuntimeException('Upload state changed concurrently');
	}

	public function nameTaken(int $galleryId, string $name, string $excluding): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->select('id')->from('proofing_uploads')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('filename', $qb->createNamedParameter($name)))
			->andWhere($qb->expr()->neq('upload_id', $qb->createNamedParameter($excluding)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['awaiting_review', 'accepted'], IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1)->executeQuery()->fetchOne() !== false;
	}

	public function stageAcceptance(string $uploadId, int $fileId): void {
		$this->setUploadField($uploadId, 'staging_file_id', $fileId);
	}

	public function accept(string $uploadId, int $fileId, string $name, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$changed = $qb->update('proofing_uploads')->set('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('filename', $qb->createNamedParameter($name))->set('status', $qb->createNamedParameter('accepted'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('awaiting_review')))->executeStatement();
		if ($changed !== 1) throw new \RuntimeException('Upload moderation state changed concurrently');
	}

	/** @return list<string> */
	public function chunkCleanupIds(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('strval', QueryResult::column($qb->select('upload_id')->from('proofing_uploads')
			->where($qb->expr()->neq('status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->eq('chunks_cleaned', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id')->setMaxResults($limit)->executeQuery()));
	}

	public function markChunksCleaned(string $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')->set('chunks_cleaned', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($id)))->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function privateCleanupRows(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_uploads')->where($qb->expr()->isNotNull('private_key'))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['accepted', 'rejected'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id')->setMaxResults($limit)->executeQuery());
	}

	/** @return list<array<string, mixed>> */
	public function legacyInboxRows(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('u.*', 'g.owner_uid')->from('proofing_uploads', 'u')
			->innerJoin('u', 'proofing_galleries', 'g', $qb->expr()->eq('g.id', 'u.gallery_id'))
			->where($qb->expr()->in('u.status', $qb->createNamedParameter(['awaiting_review', 'rejected'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->orX($qb->expr()->andX($qb->expr()->isNull('u.private_key'), $qb->expr()->isNotNull('u.file_id')), $qb->expr()->isNotNull('u.legacy_file_id')))
			->orderBy('u.id')->setMaxResults($limit)->executeQuery());
	}

	public function privatizeLegacy(string $id, int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')->set('legacy_file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('private_key', $qb->createNamedParameter($id))->set('file_id', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($id)))->executeStatement();
	}

	public function retireLegacy(string $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')->set('file_id', $qb->createNamedParameter(null))->set('legacy_file_id', $qb->createNamedParameter(null))
			->set('status', $qb->createNamedParameter('rejected'))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($id)))->executeStatement();
	}

	public function clearLegacyFile(string $id): void { $this->setUploadField($id, 'legacy_file_id', null); }
	public function clearPrivateKey(string $id): void { $this->setUploadField($id, 'private_key', null); }

	private function setUploadField(string $id, string $column, int|string|null $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_uploads')->set($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : ($value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)))
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($id)))->executeStatement();
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

	/** @return list<array<string, mixed>> */
	public function page(int $galleryId, ?int $beforeId, int $limit, string $status = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('u.*', 'g.display_name')->from('proofing_uploads', 'u')
			->leftJoin('u', 'proofing_guests', 'g', $qb->expr()->eq('u.guest_id', 'g.id'))
			->where($qb->expr()->eq('u.gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('u.id', 'DESC')->setMaxResults(max(1, min(101, $limit)));
		if ($beforeId !== null) $qb->andWhere($qb->expr()->lt('u.id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		if ($status !== '') $qb->andWhere($qb->expr()->eq('u.status', $qb->createNamedParameter($status)));
		return QueryResult::rows($qb->executeQuery());
	}

	public function countGallery(int $galleryId, string $status = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from('proofing_uploads')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		if ($status !== '') $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		return (int)$qb->executeQuery()->fetchOne();
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
