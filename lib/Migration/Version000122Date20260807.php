<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000122Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('proofing_galleries');
		if (!$table->hasColumn('mode')) {
			$table->addColumn('mode', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'presentation']);
		}
		if (!$table->hasColumn('title_sort')) {
			$table->addColumn('title_sort', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
		}
		if (!$table->hasIndex('proof_gallery_page_updated')) {
			$table->addIndex(['status', 'mode', 'source_type', 'updated_at', 'id'], 'proof_gallery_page_updated');
		}
		if (!$table->hasIndex('proof_gallery_page_title')) {
			$table->addIndex(['status', 'mode', 'source_type', 'title_sort', 'id'], 'proof_gallery_page_title');
		}
		return $schema;
	}
}
