<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Durable kiosk provisioning and upload receipts, without client secrets. */
final class Version000141Date20261001 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('proofing_kiosk_events')) {
			$table = $schema->createTable('proofing_kiosk_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('event_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('event_id', Types::STRING, ['length' => 128, 'notnull' => true]);
			$table->addColumn('request_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('config_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('folder_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('state', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'preparing']);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['owner_uid', 'event_key'], 'proof_kiosk_event_key');
			$table->addUniqueIndex(['gallery_id'], 'proof_kiosk_gallery');
		}
		if (!$schema->hasTable('proofing_kiosk_photos')) {
			$table = $schema->createTable('proofing_kiosk_photos');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('photo_id', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('checksum', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('stored_at', Types::BIGINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'photo_id'], 'proof_kiosk_photo_key');
			$table->addIndex(['gallery_id', 'stored_at'], 'proof_kiosk_photo_rate');
		}
		return $schema;
	}
}
