<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000113Date20260802 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_semantic_idx')) return $schema;
		$table = $schema->createTable('proofing_semantic_idx');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('source_etag', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('provider', Types::STRING, ['length' => 24, 'notnull' => true]);
		$table->addColumn('model', Types::STRING, ['length' => 80, 'notnull' => true]);
		$table->addColumn('vector', Types::TEXT, ['notnull' => true]);
		$table->addColumn('concepts', Types::TEXT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['gallery_id', 'file_id'], 'proof_semantic_file');
		$table->addIndex(['gallery_id', 'provider'], 'proof_semantic_gallery');
		return $schema;
	}
}
