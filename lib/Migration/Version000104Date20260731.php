<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000104Date20260731 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$galleries = $schema->getTable('proofing_galleries');
		if (!$galleries->hasColumn('source_type')) {
			$galleries->addColumn('source_type', Types::STRING, [
				'length' => 16,
				'notnull' => true,
				'default' => 'folder',
			]);
		}

		if (!$schema->hasTable('proofing_collections')) {
			$table = $schema->createTable('proofing_collections');
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['gallery_id']);
		}

		if (!$schema->hasTable('proofing_collection_items')) {
			$table = $schema->createTable('proofing_collection_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('collection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('source_gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['collection_id', 'file_id'], 'proof_collection_file');
			$table->addIndex(['collection_id', 'position'], 'proof_collection_order');
			$table->addIndex(['source_gallery_id'], 'proof_collection_source');
		}

		return $schema;
	}
}
