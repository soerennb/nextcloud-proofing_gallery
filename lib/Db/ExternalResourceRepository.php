<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ExternalResourceRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array<string, mixed>> */
	public function forGalleryUser(int $galleryId, string $userUid): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_ext_resources')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->orderBy('updated_at', 'DESC')->executeQuery());
	}

	/** @return list<array<string, mixed>> */
	public function forGalleryProvider(int $galleryId, string $provider): array {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::rows($qb->select('*')->from('proofing_ext_resources')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->orderBy('id', 'ASC')->executeQuery());
	}

	/** @return array<string, mixed>|null */
	public function find(int $galleryId, int $linkId, string $userUid, string $provider): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_ext_resources')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('public_link_id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))->executeQuery());
		return $row === false ? null : $row;
	}

	public function delete(int $galleryId, int $linkId, string $userUid, string $provider): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_ext_resources')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('public_link_id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))->executeStatement();
	}

	/** @param array<string, scalar|null> $remoteData */
	public function upsert(int $galleryId, int $linkId, string $userUid, string $provider, array $remoteData, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('proofing_ext_resources')
			->set('remote_data', $qb->createNamedParameter(json_encode($remoteData, JSON_THROW_ON_ERROR)))
			->set('sync_status', $qb->createNamedParameter('linked'))
			->set('last_error', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('public_link_id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->executeStatement();
		if ($updated === 1) return;
		$insert = $this->db->getQueryBuilder();
		$insert->insert('proofing_ext_resources')->values([
			'gallery_id' => $insert->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'public_link_id' => $insert->createNamedParameter($linkId, IQueryBuilder::PARAM_INT),
			'user_uid' => $insert->createNamedParameter($userUid),
			'provider' => $insert->createNamedParameter($provider),
			'remote_data' => $insert->createNamedParameter(json_encode($remoteData, JSON_THROW_ON_ERROR)),
			'sync_status' => $insert->createNamedParameter('linked'),
			'last_error' => $insert->createNamedParameter(null),
			'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}
}
