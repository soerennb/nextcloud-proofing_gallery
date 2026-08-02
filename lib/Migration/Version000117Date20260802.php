<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000117Date20260802 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'share_token', 'status', 'archived_at', 'created_at', 'updated_at')
			->from('proofing_galleries')
			->where($select->expr()->isNotNull('share_token'));
		$result = $select->executeQuery();
		while (($row = QueryResult::row($result)) !== false) {
			$token = trim((string)$row['share_token']);
			if ($token === '') continue;
			$archived = (string)$row['status'] === 'archived' || $row['archived_at'] !== null;
			if (!$this->linkExists($token)) {
				$insert = $this->db->getQueryBuilder();
				$insert->insert('proofing_public_links')->values([
					'gallery_id' => $insert->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT),
					'core_share_id' => $insert->createNamedParameter(null, IQueryBuilder::PARAM_INT),
					'token' => $insert->createNamedParameter($token),
					'name' => $insert->createNamedParameter('Primary link'),
					'status' => $insert->createNamedParameter($archived ? 'suspended' : 'active'),
					'is_primary' => $insert->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
					'policy' => $insert->createNamedParameter('{}'),
					'start_path' => $insert->createNamedParameter(''),
					'view_mode' => $insert->createNamedParameter('folder'),
					'group_depth' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'min_owner_rating' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'public_locale' => $insert->createNamedParameter(null),
					'created_at' => $insert->createNamedParameter((int)$row['created_at'], IQueryBuilder::PARAM_INT),
					'updated_at' => $insert->createNamedParameter((int)$row['updated_at'], IQueryBuilder::PARAM_INT),
					'revoked_at' => $insert->createNamedParameter(null, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			if ($archived) {
				$link = $this->db->getQueryBuilder();
				$link->update('proofing_public_links')->set('status', $link->createNamedParameter('suspended'))
					->where($link->expr()->eq('gallery_id', $link->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
					->andWhere($link->expr()->eq('status', $link->createNamedParameter('active')))
					->executeStatement();
				$share = $this->db->getQueryBuilder();
				$share->update('share')->set('permissions', $share->createNamedParameter(0, IQueryBuilder::PARAM_INT))
					->where($share->expr()->eq('token', $share->createNamedParameter($token)))
					->executeStatement();
			}
		}
		$result->closeCursor();
	}

	private function linkExists(string $token): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())->from('proofing_public_links')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
}

