<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class EventSetupRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|false */
	public function find(int $galleryId): array|false {
		$qb = $this->db->getQueryBuilder();
		return QueryResult::row($qb->select('*')->from('proofing_event_setups')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))->executeQuery());
	}

	public function save(int $galleryId, int $expectedRevision, string $payloadCipher, int $now): int {
		$current = $this->find($galleryId);
		if ($current === false) {
			if ($expectedRevision !== 0) throw new GalleryConflictException('Event setup changed in another session');
			$qb = $this->db->getQueryBuilder();
			$qb->insert('proofing_event_setups')->values([
				'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
				'revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'payload_cipher' => $qb->createNamedParameter($payloadCipher),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			return 1;
		}
		if ((int)$current['revision'] !== $expectedRevision) throw new GalleryConflictException('Event setup changed in another session');
		$next = $expectedRevision + 1;
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('proofing_event_setups')
			->set('revision', $qb->createNamedParameter($next, IQueryBuilder::PARAM_INT))
			->set('payload_cipher', $qb->createNamedParameter($payloadCipher))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($updated !== 1) throw new GalleryConflictException('Event setup changed in another session');
		return $next;
	}

	public function delete(int $galleryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_event_setups')->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
