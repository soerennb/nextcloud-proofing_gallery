<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Link durable event recipients back to their guided setup entry. */
final class Version000138Date20260903 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$recipients = $schema->getTable('proofing_event_recipients');
		if (!$recipients->hasColumn('setup_key')) {
			$recipients->addColumn('setup_key', Types::STRING, ['length' => 64, 'notnull' => false]);
		}
		if (!$recipients->hasIndex('proof_event_recipient_setup')) {
			$recipients->addIndex(['gallery_id', 'setup_key', 'id'], 'proof_event_recipient_setup');
		}
		return $schema;
	}
}
