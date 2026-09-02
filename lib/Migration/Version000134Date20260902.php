<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add stable shared and named-group folder scopes to event delivery. */
final class Version000134Date20260902 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$recipients = $schema->getTable('proofing_event_recipients');
		if (!$recipients->hasColumn('group_roots')) $recipients->addColumn('group_roots', Types::TEXT, ['notnull' => false]);
		if (!$schema->hasTable('proofing_event_roots')) {
			$table = $schema->createTable('proofing_event_roots');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('wave_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('recipient_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('path_snapshot', Types::TEXT, ['notnull' => true]);
			$table->addColumn('role', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('group_name', Types::STRING, ['length' => 120, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['wave_id', 'role'], 'proof_event_root_wave');
			$table->addIndex(['recipient_id', 'role'], 'proof_event_root_recipient');
		}
		return $schema;
	}
}
