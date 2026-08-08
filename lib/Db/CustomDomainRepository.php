<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class CustomDomainRepository {
	private const TABLE = 'proofing_domains';

	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string, mixed>> */
	public function gallery(int $galleryId): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')->executeQuery());
	}

	/** @return list<array<string, mixed>> */
	public function adminPage(?int $beforeId, int $limit, string $status, string $search): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.*', 'g.title', 'l.name')->from(self::TABLE, 'd')
			->innerJoin('d', 'proofing_galleries', 'g', $qb->expr()->eq('d.gallery_id', 'g.id'))
			->innerJoin('d', 'proofing_public_links', 'l', $qb->expr()->eq('d.public_link_id', 'l.id'))
			->orderBy('d.id', 'DESC')->setMaxResults(max(1, min(101, $limit)));
		$this->applyAdminFilters($qb, $status, $search);
		if ($beforeId !== null) {
			$qb->andWhere($qb->expr()->lt('d.id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		}
		return QueryResult::rows($qb->executeQuery());
	}

	public function adminCount(string $status, string $search): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from(self::TABLE, 'd')
			->innerJoin('d', 'proofing_galleries', 'g', $qb->expr()->eq('d.gallery_id', 'g.id'))
			->innerJoin('d', 'proofing_public_links', 'l', $qb->expr()->eq('d.public_link_id', 'l.id'));
		$this->applyAdminFilters($qb, $status, $search);
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return array<string, mixed>|null */
	public function find(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return array<string, mixed>|null */
	public function verifiedDomain(string $domain, int $checkedAfter): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('d.*', 'l.token')->from(self::TABLE, 'd')
			->innerJoin('d', 'proofing_public_links', 'l', $qb->expr()->eq('d.public_link_id', 'l.id'))
			->where($qb->expr()->eq('d.domain', $qb->createNamedParameter($domain)))
			->andWhere($qb->expr()->eq('d.status', $qb->createNamedParameter('verified')))
			->andWhere($qb->expr()->isNull('d.last_error'))
			->andWhere($qb->expr()->gte('d.checked_at', $qb->createNamedParameter($checkedAfter, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('l.status', $qb->createNamedParameter('active')))->executeQuery());
		return $row === false ? null : $row;
	}

	/** @return list<array<string, mixed>> */
	public function dueForVerification(int $checkedBefore, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('verified')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('checked_at'),
				$qb->expr()->lte('checked_at', $qb->createNamedParameter($checkedBefore, IQueryBuilder::PARAM_INT)),
			))
			->orderBy('checked_at', 'ASC')->setMaxResults(max(1, $limit))->executeQuery());
	}

	/** @return array<string, mixed>|null */
	public function activeLink(int $linkId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('public_link_id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('revoked')))->executeQuery());
		return $row === false ? null : $row;
	}

	public function create(int $galleryId, int $linkId, string $domain, string $token, string $requestedBy, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'public_link_id' => $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT),
			'domain' => $qb->createNamedParameter($domain), 'verification_token' => $qb->createNamedParameter($token),
			'status' => $qb->createNamedParameter('pending'), 'requested_by' => $qb->createNamedParameter($requestedBy),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return (int)$this->db->lastInsertId(self::TABLE);
	}

	public function verificationResult(int $id, bool $verified, ?string $error, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('checked_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('last_error', $qb->createNamedParameter($error));
		if ($verified) $qb->set('status', $qb->createNamedParameter('verified'))->set('verified_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		return $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('revoked')))->executeStatement() === 1;
	}

	public function revoke(int $id, int $now, ?int $galleryId = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('status', $qb->createNamedParameter('revoked'))
			->set('revoked_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($galleryId !== null) $qb->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}

	private function applyAdminFilters(IQueryBuilder $qb, string $status, string $search): void {
		if ($status === 'active') {
			$qb->andWhere($qb->expr()->neq('d.status', $qb->createNamedParameter('revoked')));
		} elseif ($status !== 'all') {
			$qb->andWhere($qb->expr()->eq('d.status', $qb->createNamedParameter($status)));
		}
		if ($search !== '') {
			$needle = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('d.domain', $qb->createNamedParameter($needle)),
				$qb->expr()->iLike('g.title', $qb->createNamedParameter($needle)),
				$qb->expr()->iLike('l.name', $qb->createNamedParameter($needle)),
			));
		}
	}
}
