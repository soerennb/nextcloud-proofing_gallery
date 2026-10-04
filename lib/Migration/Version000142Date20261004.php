<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Additive sort projection; image bytes are processed by recoverable background jobs. */
final class Version000142Date20261004 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('proofing_media_index');
		if (!$table->hasColumn('natural_name')) {
			$table->addColumn('natural_name', Types::BINARY, ['length' => 2048, 'notnull' => false]);
			$table->addColumn('captured_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('capture_missing', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('capture_state', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$table->addIndex(['gallery_id', 'natural_name', 'file_id'], 'proof_media_natural');
			$table->addIndex(['gallery_id', 'capture_missing', 'captured_at', 'file_id'], 'proof_media_capture');
			$table->addIndex(['file_id'], 'proof_media_capture_file');
		}
		$scans = $schema->getTable('proofing_media_scans');
		if (!$scans->hasColumn('sort_revision')) $scans->addColumn('sort_revision', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
		return $schema;
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$afterId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'settings')->from('proofing_galleries')
				->where($qb->expr()->eq('source_type', $qb->createNamedParameter('collection')))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')->setMaxResults(200);
			$rows = QueryResult::rows($qb->executeQuery());
			foreach ($rows as $row) {
				$afterId = (int)$row['id'];
				$settings = json_decode((string)$row['settings'], true, flags: JSON_THROW_ON_ERROR);
				// Every collection present during this migration predates sort selection.
				// Older migrations may already serialize it with the current DTO version.
				$settings['schemaVersion'] = 13;
				$settings['navigation'] = array_replace($settings['navigation'] ?? [], ['sortBy' => 'collection', 'sortDirection' => 'asc']);
				$update = $this->db->getQueryBuilder();
				$update->update('proofing_galleries')->set('settings', $update->createNamedParameter(json_encode($settings, JSON_THROW_ON_ERROR)))
					->where($update->expr()->eq('id', $update->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
		} while (count($rows) === 200);
	}
}
