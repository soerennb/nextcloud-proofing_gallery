<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keep an owner event's actor distinct from its private account recipient. */
final class Version000140Date20260908 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$events = $schema->getTable('proofing_events');
		if (!$events->hasColumn('recipient_uid')) {
			$events->addColumn('recipient_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
		}
		if (!$events->hasIndex('proof_event_recipient')) {
			$events->addIndex(['gallery_id', 'recipient_uid', 'id'], 'proof_event_recipient');
		}
		return $schema;
	}
}
