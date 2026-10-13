<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000148Date20261012 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$uploads = $schema->getTable('proofing_uploads');
		foreach (['private_key' => Types::STRING, 'legacy_file_id' => Types::BIGINT, 'staging_file_id' => Types::BIGINT] as $name => $type) {
			if (!$uploads->hasColumn($name)) $uploads->addColumn($name, $type, ['notnull' => false] + ($type === Types::STRING ? ['length' => 64] : []));
		}
		if (!$uploads->hasColumn('chunks_cleaned')) $uploads->addColumn('chunks_cleaned', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$recipients = $schema->getTable('proofing_event_recipients');
		if (!$recipients->hasColumn('claim_token')) $recipients->addColumn('claim_token', Types::STRING, ['length' => 32, 'notnull' => false]);
		if (!$recipients->hasColumn('claim_until')) $recipients->addColumn('claim_until', Types::BIGINT, ['notnull' => false]);
		if (!$schema->hasTable('proofing_sem_candidates')) {
			$table = $schema->createTable('proofing_sem_candidates');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('generation', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'generation', 'position'], 'pg_sem_candidate_position');
		}
		return $schema;
	}
}
