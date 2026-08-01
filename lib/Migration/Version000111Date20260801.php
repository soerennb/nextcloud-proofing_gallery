<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Additive 0.5 proofing data foundation.
 *
 * The legacy gallery share token deliberately remains in place. Existing
 * installations can therefore roll back while the new link model treats the
 * migrated row as the primary link.
 */
final class Version000111Date20260801 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('proofing_media_index')) {
			$table = $schema->createTable('proofing_media_index');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('parent_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('relative_path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('sort_key', Types::STRING, ['length' => 512, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('mime_type', Types::STRING, ['length' => 127, 'notnull' => true]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('etag', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('depth', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('scan_generation', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('seen_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'file_id'], 'proof_media_index_file');
			$table->addIndex(['gallery_id', 'sort_key', 'file_id'], 'proof_media_index_cursor');
			$table->addIndex(['gallery_id', 'parent_file_id'], 'proof_media_index_parent');
			$table->addIndex(['gallery_id', 'scan_generation'], 'proof_media_index_scan');
		}

		if (!$schema->hasTable('proofing_media_cull')) {
			$table = $schema->createTable('proofing_media_cull');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('rating', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('color', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'none']);
			$table->addColumn('pick_state', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'none']);
			$table->addColumn('source', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'app']);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('source_etag', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('sidecar_etag', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['owner_uid', 'file_id'], 'proof_cull_owner_file');
			$table->addIndex(['owner_uid', 'rating', 'pick_state'], 'proof_cull_owner_filter');
		}

		if (!$schema->hasTable('proofing_public_links')) {
			$table = $schema->createTable('proofing_public_links');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('core_share_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 120, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'active']);
			$table->addColumn('is_primary', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('policy', Types::TEXT, ['notnull' => true]);
			$table->addColumn('start_path', Types::TEXT, ['notnull' => true, 'default' => '']);
			$table->addColumn('view_mode', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'folder']);
			$table->addColumn('group_depth', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('min_owner_rating', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('public_locale', Types::STRING, ['length' => 16, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('revoked_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'proof_public_link_token');
			$table->addIndex(['gallery_id', 'status'], 'proof_public_link_gallery');
			$table->addIndex(['gallery_id', 'is_primary'], 'proof_public_link_primary');
		}

		if (!$schema->hasTable('proofing_guest_ratings')) {
			$table = $schema->createTable('proofing_guest_ratings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('guest_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('rating', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('pick_state', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'none']);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['gallery_id', 'guest_id', 'file_id'], 'proof_guest_rating_file');
			$table->addIndex(['gallery_id', 'file_id'], 'proof_guest_rating_result');
			$table->addIndex(['public_link_id', 'updated_at'], 'proof_guest_rating_link');
		}

		if (!$schema->hasTable('proofing_share_audit')) {
			$table = $schema->createTable('proofing_share_audit');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('guest_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('event_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('outcome', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'success']);
			$table->addColumn('reason_code', Types::STRING, ['length' => 48, 'notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['public_link_id', 'created_at'], 'proof_share_audit_link');
			$table->addIndex(['gallery_id', 'created_at'], 'proof_share_audit_gallery');
		}

		return $schema;
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'share_token', 'created_at', 'updated_at')
			->from('proofing_galleries')
			->where($select->expr()->isNotNull('share_token'));
		$result = $select->executeQuery();
		while (($row = QueryResult::row($result)) !== false) {
			$token = trim((string)$row['share_token']);
			if ($token === '' || $this->linkExists($token)) {
				continue;
			}
			$insert = $this->db->getQueryBuilder();
			$insert->insert('proofing_public_links')->values([
				'gallery_id' => $insert->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT),
				'core_share_id' => $insert->createNamedParameter(null, IQueryBuilder::PARAM_INT),
				'token' => $insert->createNamedParameter($token),
				'name' => $insert->createNamedParameter('Primary link'),
				'status' => $insert->createNamedParameter('active'),
				'is_primary' => $insert->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				'policy' => $insert->createNamedParameter('{}'),
				'start_path' => $insert->createNamedParameter(''),
				'view_mode' => $insert->createNamedParameter('folder'),
				'group_depth' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'min_owner_rating' => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'public_locale' => $insert->createNamedParameter(null),
				'created_at' => $insert->createNamedParameter((int)$row['created_at'], IQueryBuilder::PARAM_INT),
				'updated_at' => $insert->createNamedParameter((int)$row['updated_at'], IQueryBuilder::PARAM_INT),
				'revoked_at' => $insert->createNamedParameter(null, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
		$result->closeCursor();
	}

	private function linkExists(string $token): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count())
			->from('proofing_public_links')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
}
