<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Persist resumable event setup and idempotent delivery requests. */
final class Version000137Date20260902 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_event_setups')) {
			$table = $schema->createTable('proofing_event_setups');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('payload_cipher', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id'], 'proof_event_setup_gallery');
		}
		$waves = $schema->getTable('proofing_event_waves');
		if (!$waves->hasColumn('request_key')) {
			$waves->addColumn('request_key', Types::STRING, ['length' => 64, 'notnull' => false]);
			$waves->addUniqueIndex(['gallery_id', 'request_key'], 'proof_event_wave_request');
		}
		return $schema;
	}
}
