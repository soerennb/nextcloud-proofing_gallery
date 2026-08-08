<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000125Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_purge_requests')) {
			$table = $schema->createTable('proofing_purge_requests');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('requested_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('stage', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('snapshot', Types::TEXT, ['notnull' => true]);
			$table->addColumn('progress', Types::TEXT, ['notnull' => true]);
			$table->addColumn('execute_after', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['gallery_id', 'status', 'id'], 'proof_purge_gallery_status');
			$table->addIndex(['status', 'execute_after', 'id'], 'proof_purge_due');
		}
		return $schema;
	}
}
