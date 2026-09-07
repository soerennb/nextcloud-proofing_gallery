<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add a PII-free operational audit trail for event recipients. */
final class Version000135Date20260902 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_event_audit')) {
			$table = $schema->createTable('proofing_event_audit');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('recipient_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('outcome', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('reason_code', Types::STRING, ['length' => 48, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['gallery_id', 'id'], 'proof_event_audit_gallery');
			$table->addIndex(['recipient_id', 'id'], 'proof_event_audit_recipient');
		}
		return $schema;
	}
}
