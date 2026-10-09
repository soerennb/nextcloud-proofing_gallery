<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\PublicLinkPolicyService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Keep existing link choices; only matching standard primary areas inherit. */
final class Version000144Date20261009 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('proofing_public_links');
		foreach (['permissions_policy_mode', 'navigation_policy_mode'] as $column) {
			if (!$table->hasColumn($column)) $table->addColumn($column, Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'custom']);
		}
		return $schema;
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$policies = new PublicLinkPolicyService();
		$afterId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('l.id', 'l.policy', 'l.is_primary', 'l.view_mode', 'l.group_depth', 'l.allowed_roots', 'l.scope_mode', 'g.settings', 'g.delivery_mode')
				->from('proofing_public_links', 'l')->innerJoin('l', 'proofing_galleries', 'g', 'l.gallery_id = g.id')
				->where($qb->expr()->gt('l.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('l.id', 'ASC')->setMaxResults(200);
			$rows = QueryResult::rows($qb->executeQuery());
			foreach ($rows as $row) {
				$afterId = (int)$row['id'];
				try {
					$settings = GallerySettings::fromArray(json_decode((string)$row['settings'], true, flags: JSON_THROW_ON_ERROR));
					$values = PublicLinkPolicy::fromArray(json_decode((string)$row['policy'], true, flags: JSON_THROW_ON_ERROR))->jsonSerialize();
					$roots = json_decode((string)($row['allowed_roots'] ?? '[]') ?: '[]', true, flags: JSON_THROW_ON_ERROR);
					$primary = in_array($row['is_primary'], [true, 1, '1', 't', 'true'], true) && $row['delivery_mode'] === 'standard';
					$permissions = $primary && array_intersect_key($values, array_flip(PublicLinkPolicyService::PERMISSIONS)) === $policies->permissionDefaults($settings) ? 'inherit' : 'custom';
					$navigation = $policies->navigationDefaults($settings);
					$mode = $primary && is_array($roots) && $roots === [] && $row['scope_mode'] !== 'empty'
						&& $row['view_mode'] === $navigation['viewMode'] && (int)$row['group_depth'] === $navigation['groupDepth'] ? 'inherit' : 'custom';
				} catch (\InvalidArgumentException|\JsonException|\TypeError) {
					continue;
				}
				$update = $this->db->getQueryBuilder();
				$update->update('proofing_public_links')
					->set('permissions_policy_mode', $update->createNamedParameter($permissions))
					->set('navigation_policy_mode', $update->createNamedParameter($mode));
				if ($mode === 'custom' && (int)$row['group_depth'] === 0) {
					$update->set('group_depth', $update->createNamedParameter(max(1, $settings->navigation->groupDepth), IQueryBuilder::PARAM_INT));
				}
				$update->where($update->expr()->eq('id', $update->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
		} while (count($rows) === 200);
	}
}
