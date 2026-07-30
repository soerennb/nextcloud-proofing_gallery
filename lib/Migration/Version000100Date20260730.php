<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000100Date20260730 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('proofing_galleries')) {
			$table = $schema->createTable('proofing_galleries');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('slug', Types::STRING, ['length' => 96, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'draft']);
			$table->addColumn('settings', Types::TEXT, ['notnull' => true]);
			$table->addColumn('share_token', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('archived_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['owner_uid', 'slug'], 'proof_gallery_owner_slug');
			$table->addIndex(['owner_uid', 'status', 'updated_at'], 'proof_gallery_owner_status');
			$table->addIndex(['share_token'], 'proof_gallery_share_token');
		}

		if (!$schema->hasTable('proofing_managers')) {
			$table = $schema->createTable('proofing_managers');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('principal_type', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'user']);
			$table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('role', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'manager']);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'principal_type', 'user_uid'], 'proof_manager_gallery_user');
			$table->addIndex(['user_uid'], 'proof_manager_user');
		}

		if (!$schema->hasTable('proofing_guests')) {
			$table = $schema->createTable('proofing_guests');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('public_id', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('session_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('nonce_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('display_name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$table->addColumn('email_cipher', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('last_seen_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['public_id'], 'proof_guest_public');
			$table->addUniqueIndex(['session_hash'], 'proof_guest_session');
			$table->addIndex(['gallery_id', 'last_seen_at'], 'proof_guest_gallery_seen');
		}

		if (!$schema->hasTable('proofing_feedback')) {
			$table = $schema->createTable('proofing_feedback');
			$this->addActorColumns($table);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 24, 'notnull' => true]);
			$table->addColumn('value', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['gallery_id', 'file_id', 'kind'], 'proof_feedback_file');
			$table->addIndex(['guest_id'], 'proof_feedback_guest');
		}

		if (!$schema->hasTable('proofing_comments')) {
			$table = $schema->createTable('proofing_comments');
			$this->addActorColumns($table);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('parent_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('edited_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['gallery_id', 'file_id', 'created_at'], 'proof_comment_file');
			$table->addIndex(['parent_id'], 'proof_comment_parent');
		}

		if (!$schema->hasTable('proofing_annotations')) {
			$table = $schema->createTable('proofing_annotations');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('comment_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('x', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('y', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('width', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('height', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['comment_id'], 'proof_annotation_comment');
			$table->addIndex(['gallery_id', 'file_id'], 'proof_annotation_file');
		}

		if (!$schema->hasTable('proofing_selections')) {
			$table = $schema->createTable('proofing_selections');
			$this->addActorColumns($table);
			$table->addColumn('public_id', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'open']);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['public_id'], 'proof_selection_public');
			$table->addIndex(['gallery_id', 'updated_at'], 'proof_selection_gallery');
		}

		if (!$schema->hasTable('proofing_selection_items')) {
			$table = $schema->createTable('proofing_selection_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('selection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['selection_id', 'file_id'], 'proof_selection_item');
		}

		if (!$schema->hasTable('proofing_events')) {
			$table = $schema->createTable('proofing_events');
			$this->addActorColumns($table);
			$table->addColumn('event_type', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['gallery_id', 'created_at'], 'proof_event_gallery_time');
		}

		if (!$schema->hasTable('proofing_uploads')) {
			$table = $schema->createTable('proofing_uploads');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('guest_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('upload_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('filename', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('mime_type', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'pending']);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['upload_id'], 'proof_upload_public');
			$table->addIndex(['gallery_id', 'status', 'created_at'], 'proof_upload_gallery');
		}

		return $schema;
	}

	private function addActorColumns(object $table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('guest_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
	}
}
