<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000120Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();

		$links = $schema->getTable('proofing_public_links');
		if (!$links->hasColumn('review_enabled')) {
			$links->addColumn('review_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		}
		if (!$links->hasColumn('review_due_date')) {
			$links->addColumn('review_due_date', Types::STRING, ['length' => 10, 'notnull' => false]);
		}

		if (!$schema->hasTable('proofing_review_rounds')) {
			$table = $schema->createTable('proofing_review_rounds');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('round_number', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true]);
			$table->addColumn('due_date', Types::STRING, ['length' => 10, 'notnull' => false]);
			$table->addColumn('submitted_by_guest_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('submitted_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('decided_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['public_link_id', 'round_number'], 'proof_review_link_round');
			$table->addIndex(['gallery_id', 'updated_at'], 'proof_review_gallery');
			$table->addIndex(['status', 'due_date'], 'proof_review_due');
		}

		if (!$schema->hasTable('proofing_ext_resources')) {
			$table = $schema->createTable('proofing_ext_resources');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('provider', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('remote_data', Types::TEXT, ['notnull' => true]);
			$table->addColumn('sync_status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('last_error', Types::STRING, ['length' => 48, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['public_link_id', 'user_uid', 'provider'], 'proof_ext_link_user');
			$table->addIndex(['gallery_id', 'provider'], 'proof_ext_gallery');
		}

		return $schema;
	}
}
