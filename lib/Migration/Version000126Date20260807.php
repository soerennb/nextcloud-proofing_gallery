<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000126Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_retention_log')) {
			$table = $schema->createTable('proofing_retention_log');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('tag_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('outcome', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('error_code', Types::STRING, ['length' => 48, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['gallery_id', 'id'], 'proof_retention_gallery');
			$table->addIndex(['outcome', 'created_at'], 'proof_retention_health');
		}
		return $schema;
	}
}
