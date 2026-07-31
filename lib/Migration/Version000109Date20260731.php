<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000109Date20260731 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_galleries')) {
			$table = $schema->getTable('proofing_galleries');
			if (!$table->hasColumn('revision')) {
				$table->addColumn('revision', Types::INTEGER, [
					'notnull' => true,
					'unsigned' => true,
					'default' => 1,
				]);
			}
			if (!$table->hasColumn('purpose')) {
				$table->addColumn('purpose', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'custom']);
			}
			if (!$table->hasColumn('workflow_state')) {
				$table->addColumn('workflow_state', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'preparing']);
			}
			if (!$table->hasColumn('published_at')) {
				$table->addColumn('published_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('completed_at')) {
				$table->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			}
			if (!$table->hasColumn('revoked_at')) {
				$table->addColumn('revoked_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			}
		}
		return $schema;
	}
}
