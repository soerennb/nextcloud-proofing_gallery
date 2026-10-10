<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keep completed counts independent of mutable artwork caches and capped indexes. */
final class Version000146Date20261010 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_media_counts')) {
			$table = $schema->createTable('proofing_media_counts');
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('state', Types::STRING, ['length' => 16, 'default' => 'pending']);
			foreach (['image_count', 'video_count', 'counted_at', 'witness_file_id'] as $column) $table->addColumn($column, Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			foreach (['revision', 'scan_revision', 'working_images', 'working_videos', 'collection_cursor', 'updated_at'] as $column) $table->addColumn($column, Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('generation', Types::STRING, ['length' => 32, 'default' => '']);
			$table->addColumn('source_signature', Types::STRING, ['length' => 64, 'default' => '']);
			$table->addColumn('working_witness', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['gallery_id']);
			$table->addIndex(['state', 'updated_at'], 'proof_count_state');
		}
		if (!$schema->hasTable('proofing_count_queue')) {
			$table = $schema->createTable('proofing_count_queue');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]);
			foreach (['gallery_id', 'file_id', 'after_file_id'] as $column) $table->addColumn($column, Types::BIGINT, ['unsigned' => true, 'default' => 0]);
			$table->addColumn('generation', Types::STRING, ['length' => 32]);
			$table->addColumn('relative_path', Types::TEXT);
			$table->addColumn('done', Types::BOOLEAN, ['default' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'generation', 'file_id'], 'proof_count_seen');
			$table->addIndex(['gallery_id', 'generation', 'done', 'id'], 'proof_count_next');
		}
		return $schema;
	}
}
