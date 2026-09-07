<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCA\ProofingGallery\Service\PublicLinkAnchorReferences;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;

final class PublicLinkRootRepository implements PublicLinkAnchorReferences {
	public function __construct(private IDBConnection $db) {
	}

	/** @return list<array{folderId: int, pathSnapshot: string, role: string}> */
	public function findForLink(int $linkId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('folder_id', 'path_snapshot', 'scope_role')->from('proofing_link_roots')
			->where($qb->expr()->eq('public_link_id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery());
		return array_map(static fn (array $row): array => [
			'folderId' => (int)$row['folder_id'],
			'pathSnapshot' => (string)$row['path_snapshot'],
			'role' => (string)$row['scope_role'],
		], $rows);
	}

	/** @param list<array{folder: Folder, path: string, role?: string}> $roots */
	public function replace(int $linkId, array $roots): void {
		$delete = $this->db->getQueryBuilder();
		$delete->delete('proofing_link_roots')
			->where($delete->expr()->eq('public_link_id', $delete->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		foreach ($roots as $order => $root) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('proofing_link_roots')->values([
				'public_link_id' => $insert->createNamedParameter($linkId, IQueryBuilder::PARAM_INT),
				'folder_id' => $insert->createNamedParameter($root['folder']->getId(), IQueryBuilder::PARAM_INT),
				'path_snapshot' => $insert->createNamedParameter($root['path']),
				'scope_role' => $insert->createNamedParameter($root['role'] ?? 'shared'),
				'sort_order' => $insert->createNamedParameter($order, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
	}

	public function isAnchorReferenced(int $folderId): bool {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count())->from('proofing_public_links')
			->where($qb->expr()->eq('scope_anchor_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['active', 'suspended'], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchOne() > 0;
	}
}
