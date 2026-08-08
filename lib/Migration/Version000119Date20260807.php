<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000119Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('proofing_agent_requests')) {
			$table = $schema->createTable('proofing_agent_requests');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('operation', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('request_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('payload_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('response_json', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status_code', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_uid', 'operation', 'request_key'], 'proof_agent_request');
			$table->addIndex(['expires_at'], 'proof_agent_expiry');
		}

		if (!$schema->hasTable('proofing_int_outbox')) {
			$table = $schema->createTable('proofing_int_outbox');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('event_type', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('payload_json', Types::TEXT, ['notnull' => true, 'default' => '{}']);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('available_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['available_at', 'id'], 'proof_outbox_ready');
			$table->addIndex(['gallery_id'], 'proof_outbox_gallery');
		}

		return $schema;
	}
}
