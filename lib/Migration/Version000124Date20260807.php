<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Cover high-volume collaboration, results and operational cursor queries. */
final class Version000124Date20260807 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$this->index($schema, 'proofing_feedback', ['gallery_id', 'guest_id', 'file_id', 'kind'], 'proof_feedback_scope');
		$this->index($schema, 'proofing_comments', ['gallery_id', 'guest_id', 'id'], 'proof_comment_scope');
		$this->index($schema, 'proofing_comments', ['gallery_id', 'file_id', 'id'], 'proof_comment_file');
		$this->index($schema, 'proofing_selections', ['gallery_id', 'guest_id', 'id'], 'proof_selection_scope');
		$this->index($schema, 'proofing_events', ['gallery_id', 'guest_id', 'id'], 'proof_event_scope_cursor');
		$this->index($schema, 'proofing_uploads', ['gallery_id', 'status', 'id'], 'proof_upload_gallery_page');
		$this->index($schema, 'proofing_share_audit', ['gallery_id', 'id'], 'proof_audit_gallery_page');
		$this->index($schema, 'proofing_guest_ratings', ['gallery_id', 'file_id', 'guest_id'], 'proof_rating_gallery_page');
		return $schema;
	}

	/** @param list<string> $columns */
	private function index(ISchemaWrapper $schema, string $tableName, array $columns, string $name): void {
		if (!$schema->hasTable($tableName)) return;
		$table = $schema->getTable($tableName);
		if (!$table->hasIndex($name)) $table->addIndex($columns, $name);
	}
}
