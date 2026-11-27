<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Per-folder count cache: direct counts for the current walk, subtree totals for unchanged folders. */
final class Version000149Date20261015 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_count_folders')) {
			$table = $schema->createTable('proofing_count_folders');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('folder_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('parent_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('generation', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => '']);
			$table->addColumn('etag', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => '']);
			$table->addColumn('cacheable', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('hit', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('direct_images', Types::BIGINT, ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('direct_videos', Types::BIGINT, ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('direct_witness', Types::BIGINT, ['unsigned' => true, 'notnull' => false]);
			$table->addColumn('sub_etag', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('sub_images', Types::BIGINT, ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('sub_videos', Types::BIGINT, ['unsigned' => true, 'notnull' => true, 'default' => 0]);
			$table->addColumn('sub_witness', Types::BIGINT, ['unsigned' => true, 'notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'folder_id'], 'proof_count_folder');
			$table->addIndex(['gallery_id', 'generation'], 'proof_count_folder_gen');
		}
		return $schema;
	}
}
