<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Persist bounded media-index scans so workers can resume after every batch. */
final class Version000123Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_media_scans')) {
			$table = $schema->createTable('proofing_media_scans');
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('generation', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('root_storage_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('root_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('indexed_count', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('truncated', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('dirty', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['gallery_id']);
			$table->addIndex(['status', 'updated_at'], 'proof_media_scan_status');
		}
		if (!$schema->hasTable('proofing_media_scan_queue')) {
			$table = $schema->createTable('proofing_media_scan_queue');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('generation', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('parent_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('relative_path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('depth', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('after_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'generation', 'parent_file_id'], 'proof_media_scan_folder');
			$table->addIndex(['gallery_id', 'generation', 'id'], 'proof_media_scan_next');
		}
		return $schema;
	}
}
