<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\FeedbackPolicyService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Preserve existing feedback restrictions while making inheritance explicit. */
final class Version000143Date20261008 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db, private IConfig $config) {
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('proofing_public_links');
		if (!$table->hasColumn('feedback_policy_mode')) {
			$table->addColumn('feedback_policy_mode', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'custom']);
		}
		return $schema;
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Fresh installations run schema-only migrations. This fallback also handles
		// old installations with absent or malformed JSON without discarding their settings.
		if ($this->config->getAppValue(Application::APP_ID, 'installed_version', '') !== '') {
			$this->config->setAppValue(Application::APP_ID, 'guestRatingsDefault', '0');
		}
		$afterId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('l.id', 'l.policy', 'g.settings')->from('proofing_public_links', 'l')
				->innerJoin('l', 'proofing_galleries', 'g', 'l.gallery_id = g.id')
				->where($qb->expr()->eq('l.is_primary', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
				->andWhere($qb->expr()->eq('g.delivery_mode', $qb->createNamedParameter('standard')))
				->andWhere($qb->expr()->gt('l.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('l.id', 'ASC')->setMaxResults(200);
			$rows = QueryResult::rows($qb->executeQuery());
			foreach ($rows as $row) {
				$afterId = (int)$row['id'];
				try {
					$policy = PublicLinkPolicy::fromArray(json_decode((string)$row['policy'], true, flags: JSON_THROW_ON_ERROR));
					$settings = GallerySettings::fromArray(json_decode((string)$row['settings'], true, flags: JSON_THROW_ON_ERROR));
					foreach (FeedbackPolicyService::FEATURES as $feature => $_capability) {
						if ($policy->allows($feature) !== $settings->review->enabled($feature)) continue 2;
					}
				} catch (\InvalidArgumentException|\JsonException|\TypeError) {
					// A malformed legacy policy keeps its own permissions, never broader defaults.
					continue;
				}
				$update = $this->db->getQueryBuilder();
				$update->update('proofing_public_links')->set('feedback_policy_mode', $update->createNamedParameter('inherit'))
					->where($update->expr()->eq('id', $update->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
		} while (count($rows) === 200);
	}
}
