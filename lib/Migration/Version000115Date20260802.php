<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000115Date20260802 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_domains')) return $schema;
		$table = $schema->createTable('proofing_domains');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('gallery_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('public_link_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('domain', Types::STRING, ['length' => 253, 'notnull' => true]);
		$table->addColumn('verification_token', Types::STRING, ['length' => 80, 'notnull' => true]);
		$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('requested_by', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('last_error', Types::STRING, ['length' => 120, 'notnull' => false]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('checked_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('verified_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('revoked_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['domain'], 'proof_domain_unique');
		$table->addIndex(['gallery_id', 'status'], 'proof_domain_gallery');
		$table->addIndex(['public_link_id', 'status'], 'proof_domain_link');
		return $schema;
	}
}
