<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the cursor and retention columns needed by bounded maintenance jobs.
 *
 * All changes are additive so an upgrade never has to parse gallery JSON or
 * rewrite a large table while Nextcloud is in maintenance mode.
 */
final class Version000121Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();

		$galleries = $schema->getTable('proofing_galleries');
		foreach (['lifecycle_revoke_at', 'lifecycle_archive_at', 'lifecycle_next_at'] as $column) {
			if (!$galleries->hasColumn($column)) {
				$galleries->addColumn($column, Types::BIGINT, ['notnull' => false]);
			}
		}
		if (!$galleries->hasIndex('proof_gallery_lifecycle_due')) {
			$galleries->addIndex(['status', 'lifecycle_next_at', 'id'], 'proof_gallery_lifecycle_due');
		}

		$this->addIndex($schema, 'proofing_events', ['created_at', 'id'], 'proof_event_cleanup');
		$this->addIndex($schema, 'proofing_share_audit', ['created_at', 'id'], 'proof_audit_cleanup');
		$this->addIndex($schema, 'proofing_uploads', ['status', 'updated_at', 'id'], 'proof_upload_cleanup');
		$this->addIndex($schema, 'proofing_guests', ['expires_at', 'id'], 'proof_guest_expiry');
		$this->addIndex($schema, 'proofing_native_notify', ['status', 'updated_at', 'id'], 'proof_native_cleanup');
		$this->addIndex($schema, 'proofing_notify_queue', ['status', 'updated_at', 'id'], 'proof_notify_cleanup');
		$this->addIndex($schema, 'proofing_domains', ['status', 'checked_at', 'id'], 'proof_domain_revalidate');
		$this->addIndex($schema, 'proofing_media_index', ['gallery_id', 'mtime', 'file_id'], 'proof_media_mtime_cursor');
		$this->addIndex($schema, 'proofing_media_index', ['gallery_id', 'size', 'file_id'], 'proof_media_size_cursor');

		$outbox = $schema->getTable('proofing_int_outbox');
		if (!$outbox->hasColumn('status')) {
			$outbox->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
		}
		if (!$outbox->hasColumn('dead_at')) {
			$outbox->addColumn('dead_at', Types::BIGINT, ['notnull' => false]);
		}
		if (!$outbox->hasColumn('last_error_code')) {
			$outbox->addColumn('last_error_code', Types::STRING, ['length' => 96, 'notnull' => false]);
		}
		if (!$outbox->hasIndex('proof_outbox_status_due')) {
			$outbox->addIndex(['status', 'available_at', 'id'], 'proof_outbox_status_due');
		}
		if (!$outbox->hasIndex('proof_outbox_dead_cleanup')) {
			$outbox->addIndex(['status', 'dead_at', 'id'], 'proof_outbox_dead_cleanup');
		}

		return $schema;
	}

	/** @param list<string> $columns */
	private function addIndex(ISchemaWrapper $schema, string $tableName, array $columns, string $name): void {
		if (!$schema->hasTable($tableName)) {
			return;
		}
		$table = $schema->getTable($tableName);
		if (!$table->hasIndex($name)) {
			$table->addIndex($columns, $name);
		}
	}
}
