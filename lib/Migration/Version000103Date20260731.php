<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000103Date20260731 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_summaries')) {
			$table = $schema->createTable('proofing_summaries');
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('folder_etag', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('media_total', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('cover_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('cover_mime_type', Types::STRING, ['length' => 127, 'notnull' => false]);
			$table->addColumn('scanned_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['gallery_id']);
			$table->addIndex(['folder_id', 'folder_etag'], 'proof_summary_folder');
		}
		return $schema;
	}
}
