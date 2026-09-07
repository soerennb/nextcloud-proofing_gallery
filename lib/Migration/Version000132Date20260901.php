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

/** Persist event intent and stable file-node scopes for public links. */
final class Version000132Date20260901 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$galleries = $schema->getTable('proofing_galleries');
		if (!$galleries->hasColumn('delivery_mode')) {
			$galleries->addColumn('delivery_mode', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'standard']);
		}
		$links = $schema->getTable('proofing_public_links');
		if (!$links->hasColumn('scope_mode')) {
			$links->addColumn('scope_mode', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'legacy']);
		}
		if (!$schema->hasTable('proofing_link_roots')) {
			$roots = $schema->createTable('proofing_link_roots');
			$roots->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$roots->addColumn('public_link_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$roots->addColumn('folder_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$roots->addColumn('path_snapshot', Types::STRING, ['length' => 1024, 'notnull' => true]);
			$roots->addColumn('scope_role', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'shared']);
			$roots->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$roots->setPrimaryKey(['id']);
			$roots->addUniqueIndex(['public_link_id', 'folder_id'], 'proofing_link_root_node');
			$roots->addIndex(['public_link_id', 'sort_order'], 'proofing_link_root_order');
		}
		$recipients = $schema->getTable('proofing_event_recipients');
		if (!$recipients->hasColumn('folder_id')) {
			$recipients->addColumn('folder_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		return $schema;
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$eventGalleryIds = QueryResult::column($qb->selectDistinct('gallery_id')->from('proofing_event_recipients')->executeQuery());
		if ($eventGalleryIds === []) return;
		$update = $this->db->getQueryBuilder();
		$update->update('proofing_galleries')
			->set('delivery_mode', $update->createNamedParameter('event'))
			->where($update->expr()->in('id', $update->createNamedParameter(array_map('intval', $eventGalleryIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
