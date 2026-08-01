<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000101Date20260730 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('proofing_managers');
		if (!$table->hasColumn('principal_type')) {
			$table->addColumn('principal_type', Types::STRING, [
				'length' => 16,
				'notnull' => true,
				'default' => 'user',
			]);
		}
		if ($table->hasIndex('proof_manager_gallery_user')) {
			$table->dropIndex('proof_manager_gallery_user');
		}
		$table->addUniqueIndex(['gallery_id', 'principal_type', 'user_uid'], 'proof_manager_gallery_user');

		return $schema;
	}
}
