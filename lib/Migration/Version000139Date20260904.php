<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Restore the shared/group/private roles on already released event link roots. */
final class Version000139Date20260904 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$recipients = QueryResult::rows($select->select('id', 'wave_id', 'public_link_id')
			->from('proofing_event_recipients')
			->where($select->expr()->isNotNull('public_link_id'))
			->andWhere($select->expr()->isNotNull('wave_id'))
			->executeQuery());
		foreach ($recipients as $recipient) {
			$roots = $this->db->getQueryBuilder();
			$rows = QueryResult::rows($roots->select('folder_id', 'role')->from('proofing_event_roots')
				->where($roots->expr()->eq('wave_id', $roots->createNamedParameter((int)$recipient['wave_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($roots->expr()->orX(
					$roots->expr()->isNull('recipient_id'),
					$roots->expr()->eq('recipient_id', $roots->createNamedParameter((int)$recipient['id'], IQueryBuilder::PARAM_INT)),
				))->executeQuery());
			foreach ($rows as $row) {
				$role = (string)$row['role'];
				if (!in_array($role, ['shared', 'group', 'private'], true)) continue;
				$update = $this->db->getQueryBuilder();
				$update->update('proofing_link_roots')
					->set('scope_role', $update->createNamedParameter($role))
					->where($update->expr()->eq('public_link_id', $update->createNamedParameter((int)$recipient['public_link_id'], IQueryBuilder::PARAM_INT)))
					->andWhere($update->expr()->eq('folder_id', $update->createNamedParameter((int)$row['folder_id'], IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		}
	}
}
