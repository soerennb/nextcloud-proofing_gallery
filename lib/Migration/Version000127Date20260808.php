<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keep filtered administrator domain pages bounded as their history grows. */
final class Version000127Date20260808 extends SimpleMigrationStep {
	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_domains')) {
			$table = $schema->getTable('proofing_domains');
			if (!$table->hasIndex('proof_domain_admin_page')) {
				$table->addIndex(['status', 'id'], 'proof_domain_admin_page');
			}
		}
		return $schema;
	}
}
