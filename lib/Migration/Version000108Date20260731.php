<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000108Date20260731 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_versions')) {
			$table = $schema->createTable('proofing_versions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('version_id', Types::STRING, ['length' => 48, 'notnull' => true]);
			$table->addColumn('filename', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('mime_type', Types::STRING, ['length' => 128, 'notnull' => true]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['version_id'], 'proof_version_id');
			$table->addIndex(['gallery_id', 'file_id', 'created_at'], 'proof_version_file');
		}
		return $schema;
	}
}
