<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add per-link selection constraints and associate drafts with their client link. */
final class Version000136Date20260902 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$links = $schema->getTable('proofing_public_links');
		if (!$links->hasColumn('review_selection_min')) {
			$links->addColumn('review_selection_min', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		}
		if (!$links->hasColumn('review_selection_max')) {
			$links->addColumn('review_selection_max', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		}
		$selections = $schema->getTable('proofing_selections');
		if (!$selections->hasColumn('public_link_id')) {
			$selections->addColumn('public_link_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$selections->addIndex(['public_link_id', 'guest_id', 'updated_at'], 'proof_selection_link_guest');
		}
		return $schema;
	}
}
