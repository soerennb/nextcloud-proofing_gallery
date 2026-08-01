<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000107Date20260731 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_notify_subs')) {
			$table = $schema->createTable('proofing_notify_subs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('event_types', Types::TEXT, ['notnull' => true]);
			$table->addColumn('frequency', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('locale', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'auto']);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('unsubscribe_token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'user_uid'], 'proof_notify_gallery_user');
			$table->addUniqueIndex(['unsubscribe_token'], 'proof_notify_unsubscribe');
		}
		if (!$schema->hasTable('proofing_notify_queue')) {
			$table = $schema->createTable('proofing_notify_queue');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('subscription_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('event_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$table->addColumn('available_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['subscription_id', 'event_id'], 'proof_notify_sub_event');
			$table->addIndex(['status', 'available_at'], 'proof_notify_due');
		}
		return $schema;
	}
}
