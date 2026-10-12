<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Pending outcomes must survive expiry until their effects are reconciled. */
final class Version000147Date20261012 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('proofing_agent_requests');
		if (!$table->hasColumn('execution_state')) $table->addColumn('execution_state', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'reserved']);
		return $schema;
	}
}
