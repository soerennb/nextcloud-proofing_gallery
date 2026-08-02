<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class VideoDerivativeRepository {
	private const TABLE = 'proofing_video_deriv';

	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function find(string $ownerUid, int $fileId, string $profile = 'web-h264'): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('profile', $qb->createNamedParameter($profile)));
		$row = QueryResult::row($qb->executeQuery());
		return $row === false ? null : $row;
	}

	public function enqueue(string $ownerUid, int $fileId, string $etag, int $now, string $profile = 'web-h264'): bool {
		$row = $this->find($ownerUid, $fileId, $profile);
		if ($row === null) {
			$qb = $this->db->getQueryBuilder();
			try {
				$qb->insert(self::TABLE)->values([
					'owner_uid' => $qb->createNamedParameter($ownerUid),
					'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'source_etag' => $qb->createNamedParameter($etag),
					'profile' => $qb->createNamedParameter($profile),
					'status' => $qb->createNamedParameter('pending'),
					'size' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
				return true;
			} catch (UniqueConstraintViolationException) {
				// A concurrent request created the queue row. Re-evaluate that row below.
				$row = $this->find($ownerUid, $fileId, $profile);
				if ($row === null) throw new \RuntimeException('Video derivative queue conflict could not be resolved');
			}
		}
		if ((string)$row['source_etag'] === $etag) {
			if (in_array($row['status'], ['pending', 'processing', 'ready'], true)) return false;
			if ($row['status'] === 'failed' && ((int)$row['attempts'] >= 3 || (int)$row['updated_at'] > $now - 300)) return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('source_etag', $qb->createNamedParameter($etag))
			->set('status', $qb->createNamedParameter('pending'))->set('storage_key', $qb->createNamedParameter(null))
			->set('poster_key', $qb->createNamedParameter(null))->set('size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))->set('error_code', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
		return true;
	}

	public function claim(int $id, string $etag, int $now, int $limit): bool {
		$leaseExpiredAt = $now - 3600;
		$active = $this->db->getQueryBuilder();
		$active->select($active->func()->count())->from(self::TABLE)
			->where($active->expr()->eq('status', $active->createNamedParameter('processing')))
			->andWhere($active->expr()->gt('updated_at', $active->createNamedParameter($leaseExpiredAt, IQueryBuilder::PARAM_INT)));
		if ((int)$active->executeQuery()->fetchOne() >= $limit) return false;
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('status', $qb->createNamedParameter('processing'))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_etag', $qb->createNamedParameter($etag)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->in('status', $qb->createNamedParameter(['pending', 'failed'], IQueryBuilder::PARAM_STR_ARRAY)),
				$qb->expr()->andX(
					$qb->expr()->eq('status', $qb->createNamedParameter('processing')),
					$qb->expr()->lte('updated_at', $qb->createNamedParameter($leaseExpiredAt, IQueryBuilder::PARAM_INT)),
				),
			));
		return $qb->executeStatement() === 1;
	}

	public function complete(int $id, string $etag, string $storageKey, string $posterKey, int $size, int $now): bool {
		return $this->finish($id, $etag, 'ready', $now, $storageKey, $posterKey, $size, null);
	}

	public function fail(int $id, string $etag, string $errorCode, int $now): bool {
		return $this->finish($id, $etag, 'failed', $now, null, null, 0, mb_substr($errorCode, 0, 48));
	}

	private function finish(int $id, string $etag, string $status, int $now, ?string $storageKey, ?string $posterKey, int $size, ?string $error): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('status', $qb->createNamedParameter($status))
			->set('storage_key', $qb->createNamedParameter($storageKey))->set('poster_key', $qb->createNamedParameter($posterKey))
			->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))->set('error_code', $qb->createNamedParameter($error))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source_etag', $qb->createNamedParameter($etag)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('processing')));
		return $qb->executeStatement() === 1;
	}

	/** @return array{pending: int, processing: int, failed: int, ready: int, bytes: int} */
	public function health(): array {
		$counts = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'ready' => 0, 'bytes' => 0];
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', $qb->func()->count('*', 'amount'))->from(self::TABLE)->groupBy('status');
		foreach (QueryResult::rows($qb->executeQuery()) as $row) {
			$status = (string)$row['status'];
			if (array_key_exists($status, $counts)) $counts[$status] = (int)$row['amount'];
		}
		$size = $this->db->getQueryBuilder();
		$size->select($size->func()->sum('size'))->from(self::TABLE)->where($size->expr()->eq('status', $size->createNamedParameter('ready')));
		$counts['bytes'] = (int)$size->executeQuery()->fetchOne();
		return $counts;
	}

	/** @return list<array{id: int, storage_key: ?string, poster_key: ?string}> */
	public function expired(int $before, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'storage_key', 'poster_key')->from(self::TABLE)
			->where($qb->expr()->lt('updated_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		return array_map(static fn (array $row): array => [
			'id' => (int)$row['id'],
			'storage_key' => is_string($row['storage_key']) ? $row['storage_key'] : null,
			'poster_key' => is_string($row['poster_key']) ? $row['poster_key'] : null,
		], QueryResult::rows($qb->executeQuery()));
	}

	/** @param list<int> $ids */
	public function delete(array $ids): int {
		if ($ids === []) return 0;
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}
}
