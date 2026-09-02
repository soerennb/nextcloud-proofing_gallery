<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add durable event release waves and one-time encrypted PIN handoffs. */
final class Version000133Date20260902 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_event_waves')) {
			$table = $schema->createTable('proofing_event_waves');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true]);
			$table->addColumn('shared_roots', Types::TEXT, ['notnull' => true]);
			$table->addColumn('policy', Types::TEXT, ['notnull' => true]);
			$table->addColumn('expires_at', Types::STRING, ['length' => 10, 'notnull' => false]);
			$table->addColumn('release_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('send_invitations', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('total_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('processed_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('failed_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['gallery_id', 'created_at'], 'proof_event_wave_gallery');
			$table->addIndex(['status', 'release_at'], 'proof_event_wave_release');
		}
		$recipients = $schema->getTable('proofing_event_recipients');
		if ($recipients->hasIndex('proof_event_recipient_folder')) $recipients->dropIndex('proof_event_recipient_folder');
		foreach ([
			'wave_id' => [Types::BIGINT, ['notnull' => false, 'unsigned' => true]],
			'pin_cipher' => [Types::TEXT, ['notnull' => false]],
			'publication_status' => [Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'published']],
			'invitation_status' => [Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'not_requested']],
			'error_code' => [Types::STRING, ['length' => 64, 'notnull' => false]],
			'attempts' => [Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]],
		] as $name => [$type, $columnOptions]) {
			if (!$recipients->hasColumn($name)) $recipients->addColumn($name, $type, $columnOptions);
		}
		if (!$recipients->hasIndex('proof_event_recipient_wave')) $recipients->addIndex(['wave_id', 'publication_status'], 'proof_event_recipient_wave');
		if (!$schema->hasTable('proofing_pin_handoffs')) {
			$table = $schema->createTable('proofing_pin_handoffs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('wave_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('content_cipher', Types::TEXT, ['notnull' => true]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('consumed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['wave_id'], 'proof_pin_handoff_wave');
		}
		return $schema;
	}
}
