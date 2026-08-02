<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class LivePushRepository {
	private const TABLE = 'proofing_live_push';

	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string, mixed>> */
	public function gallery(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->executeQuery());
	}

	/** @return array<string, mixed>|null */
	public function activeByUsername(string $username): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('username', $qb->createNamedParameter($username)))
			->andWhere($qb->expr()->isNull('revoked_at'))->executeQuery());
		return $row === false ? null : $row;
	}

	public function create(int $galleryId, string $username, string $secretHash, string $label, string $path, string $createdBy, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'username' => $qb->createNamedParameter($username),
			'secret_hash' => $qb->createNamedParameter($secretHash),
			'label' => $qb->createNamedParameter($label),
			'target_path' => $qb->createNamedParameter($path),
			'created_by' => $qb->createNamedParameter($createdBy),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'upload_count' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'bytes_received' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return (int)$this->db->lastInsertId(self::TABLE);
	}

	public function rotate(int $galleryId, int $id, string $secretHash): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)->set('secret_hash', $qb->createNamedParameter($secretHash))
			->set('revoked_at', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement() === 1;
	}

	public function revoke(int $galleryId, int $id, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)->set('revoked_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('revoked_at'))->executeStatement() === 1;
	}

	public function recordUpload(int $id, int $bytes, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('last_used_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('upload_count', $qb->func()->add('upload_count', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->set('bytes_received', $qb->func()->add('bytes_received', $qb->createNamedParameter($bytes, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @return array{active: int, uploads: int, bytes: int} */
	public function health(): array {
		$active = $this->db->getQueryBuilder();
		$active->select($active->func()->count())->from(self::TABLE)->where($active->expr()->isNull('revoked_at'));
		$uploads = $this->db->getQueryBuilder();
		$uploads->select($uploads->func()->sum('upload_count'))->from(self::TABLE);
		$bytes = $this->db->getQueryBuilder();
		$bytes->select($bytes->func()->sum('bytes_received'))->from(self::TABLE);
		return ['active' => (int)$active->executeQuery()->fetchOne(), 'uploads' => (int)$uploads->executeQuery()->fetchOne(), 'bytes' => (int)$bytes->executeQuery()->fetchOne()];
	}
}
