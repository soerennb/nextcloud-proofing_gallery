<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add copy-free multi-root scopes and recipient metadata for volume events. */
final class Version000130Date20260901 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$links = $schema->getTable('proofing_public_links');
		if (!$links->hasColumn('allowed_roots')) {
			$links->addColumn('allowed_roots', Types::TEXT, ['notnull' => false]);
		}

		if (!$schema->hasTable('proofing_event_recipients')) {
			$table = $schema->createTable('proofing_event_recipients');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('folder_path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('display_name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$table->addColumn('email_cipher', Types::TEXT, ['notnull' => false]);
			$table->addColumn('locale', Types::STRING, ['length' => 16, 'notnull' => false]);
			$table->addColumn('delivery_status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'draft']);
			$table->addColumn('invited_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'folder_path'], 'proof_event_recipient_folder');
			$table->addUniqueIndex(['public_link_id'], 'proof_event_recipient_link');
			$table->addIndex(['gallery_id', 'delivery_status'], 'proof_event_recipient_status');
		}
		return $schema;
	}
}
