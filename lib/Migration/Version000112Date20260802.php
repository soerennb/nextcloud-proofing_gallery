<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000112Date20260802 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_video_deriv')) return $schema;

		$table = $schema->createTable('proofing_video_deriv');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('source_etag', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('profile', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('storage_key', Types::STRING, ['length' => 180, 'notnull' => false]);
		$table->addColumn('poster_key', Types::STRING, ['length' => 180, 'notnull' => false]);
		$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->addColumn('error_code', Types::STRING, ['length' => 48, 'notnull' => false]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['owner_uid', 'file_id', 'profile'], 'proof_video_source');
		$table->addIndex(['status', 'updated_at'], 'proof_video_queue');
		return $schema;
	}
}
