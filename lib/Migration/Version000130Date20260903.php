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

/** Complete the existing guest-or-account collaboration actor model. */
final class Version000130Date20260903 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Older builds did not enforce account feedback uniqueness. Keep the newest
		// value if concurrent requests created duplicate account rows.
		$qb = $this->db->getQueryBuilder();
		$duplicates = QueryResult::rows($qb->select('gallery_id', 'file_id', 'kind', 'actor_uid')
			->selectAlias($qb->func()->max('id'), 'keep_id')
			->from('proofing_feedback')
			->where($qb->expr()->isNotNull('actor_uid'))
			->groupBy('gallery_id', 'file_id', 'kind', 'actor_uid')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->executeQuery());
		foreach ($duplicates as $row) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete('proofing_feedback')
				->where($delete->expr()->eq('gallery_id', $delete->createNamedParameter((int)$row['gallery_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($delete->expr()->eq('file_id', $delete->createNamedParameter((int)$row['file_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($delete->expr()->eq('kind', $delete->createNamedParameter((string)$row['kind'])))
				->andWhere($delete->expr()->eq('actor_uid', $delete->createNamedParameter((string)$row['actor_uid'])))
				->andWhere($delete->expr()->neq('id', $delete->createNamedParameter((int)$row['keep_id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$feedback = $schema->getTable('proofing_feedback');
		if (!$feedback->hasIndex('proof_feedback_actor_kind')) {
			$feedback->addUniqueIndex(['gallery_id', 'file_id', 'kind', 'actor_uid'], 'proof_feedback_actor_kind');
			$changed = true;
		}
		foreach ([
			'proofing_comments' => ['proof_comment_actor', ['gallery_id', 'actor_uid', 'created_at']],
			'proofing_selections' => ['proof_selection_actor', ['gallery_id', 'actor_uid', 'updated_at']],
			'proofing_events' => ['proof_event_actor', ['gallery_id', 'actor_uid', 'created_at']],
		] as $tableName => [$indexName, $columns]) {
			$table = $schema->getTable($tableName);
			if (!$table->hasIndex($indexName)) {
				$table->addIndex($columns, $indexName);
				$changed = true;
			}
		}

		$rounds = $schema->getTable('proofing_review_rounds');
		if (!$rounds->hasColumn('submitted_by_actor_uid')) {
			$rounds->addColumn('submitted_by_actor_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
			$changed = true;
		}
		if (!$rounds->hasIndex('proof_review_actor')) {
			$rounds->addIndex(['submitted_by_actor_uid'], 'proof_review_actor');
			$changed = true;
		}

		$ratings = $schema->getTable('proofing_guest_ratings');
		if (!$ratings->hasColumn('actor_uid')) {
			$ratings->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
			$changed = true;
		}
		if ($ratings->getColumn('guest_id')->getNotnull()) {
			$ratings->changeColumn('guest_id', ['notnull' => false]);
			$changed = true;
		}
		if (!$ratings->hasIndex('proof_actor_rating_file')) {
			$ratings->addUniqueIndex(['gallery_id', 'actor_uid', 'file_id'], 'proof_actor_rating_file');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
