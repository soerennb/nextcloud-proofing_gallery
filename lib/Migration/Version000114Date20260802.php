<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000114Date20260802 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_live_push')) return $schema;
		$table = $schema->createTable('proofing_live_push');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('username', Types::STRING, ['length' => 80, 'notnull' => true]);
		$table->addColumn('secret_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('label', Types::STRING, ['length' => 80, 'notnull' => true]);
		$table->addColumn('target_path', Types::STRING, ['length' => 500, 'notnull' => true, 'default' => '']);
		$table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('last_used_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('revoked_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('upload_count', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->addColumn('bytes_received', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['username'], 'proof_live_username');
		$table->addIndex(['gallery_id', 'revoked_at'], 'proof_live_gallery');
		return $schema;
	}
}
