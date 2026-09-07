<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Store the empty native-share anchor used by disjoint public link scopes. */
final class Version000131Date20260901 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$links = $schema->getTable('proofing_public_links');
		if (!$links->hasColumn('scope_anchor_id')) {
			$links->addColumn('scope_anchor_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		}
		return $schema;
	}
}
