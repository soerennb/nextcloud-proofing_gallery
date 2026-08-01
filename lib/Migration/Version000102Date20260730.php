<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000102Date20260730 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$feedback = $schema->getTable('proofing_feedback');
		if (!$feedback->hasIndex('proof_feedback_guest_kind')) {
			$feedback->addUniqueIndex(
				['gallery_id', 'file_id', 'kind', 'guest_id'],
				'proof_feedback_guest_kind',
			);
		}

		$selections = $schema->getTable('proofing_selections');
		if (!$selections->hasColumn('message')) {
			$selections->addColumn('message', Types::TEXT, ['notnull' => true, 'default' => '']);
		}
		return $schema;
	}
}
