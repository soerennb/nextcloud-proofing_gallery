<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000110Date20260801 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_notify_subs')) {
			$table = $schema->getTable('proofing_notify_subs');
			if (!$table->hasColumn('email_enabled')) {
				$table->addColumn('email_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			}
			if (!$table->hasColumn('native_enabled')) {
				// Existing subscriptions remain email-only after the upgrade.
				$table->addColumn('native_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			}
			if (!$table->hasColumn('native_event_types')) {
				$table->addColumn('native_event_types', Types::TEXT, ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('proofing_native_notify')) {
			$table = $schema->createTable('proofing_native_notify');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('category', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('event_count', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('latest_event_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('available_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'user_uid', 'category'], 'proof_native_gallery_user_cat');
			$table->addIndex(['status', 'available_at'], 'proof_native_due');
		}

		return $schema;
	}
}
