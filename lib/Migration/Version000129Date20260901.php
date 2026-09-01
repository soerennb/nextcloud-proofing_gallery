<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Canonicalize design settings and add reusable owner-scoped assets. */
final class Version000129Date20260901 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db, private IConfig $config) {
	}

	/** @param array<string, mixed> $options */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('proofing_design_assets')) return null;
		$table = $schema->createTable('proofing_design_assets');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('public_id', Types::STRING, ['length' => 40, 'notnull' => true]);
		$table->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
		$table->addColumn('storage_key', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('mime_type', Types::STRING, ['length' => 127, 'notnull' => true]);
		$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('width', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('height', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['public_id'], 'proofing_design_asset_public');
		$table->addIndex(['owner_uid', 'kind'], 'proofing_design_asset_owner');
		return $schema;
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->canonicalizeTable('proofing_galleries', false);
		$this->canonicalizeTable('proofing_presets', true);
		$raw = $this->config->getAppValue(Application::APP_ID, 'galleryDefaults', '');
		$settings = json_decode($raw, true);
		if (is_array($settings)) {
			$this->config->setAppValue(Application::APP_ID, 'galleryDefaults', $this->canonical($settings));
		}
	}

	private function canonicalizeTable(string $table, bool $portable): void {
		$lastId = 0;
		do {
			$select = $this->db->getQueryBuilder();
			$result = $select->select('id', 'settings')->from($table)
				->where($select->expr()->gt('id', $select->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')->setMaxResults(100)->executeQuery();
			$rows = QueryResult::rows($result);
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				$settings = json_decode((string)$row['settings'], true);
				if (!is_array($settings)) continue;
				$update = $this->db->getQueryBuilder();
				$update->update($table)->set('settings', $update->createNamedParameter($this->canonical($settings, $portable)))
					->where($update->expr()->eq('id', $update->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		} while (count($rows) === 100);
	}

	/** @param array<string, mixed> $settings */
	private function canonical(array $settings, bool $portable = false): string {
		$canonical = GallerySettings::fromArray($settings)->canonical();
		if ($portable) {
			$canonical['presentation']['heroFileId'] = null;
			$canonical['presentation']['logoFileId'] = null;
			$canonical['presentation']['instanceLogoAssetId'] = null;
			$canonical['presentation']['instanceStudioName'] = '';
			if ($canonical['presentation']['logoMode'] === 'gallery') $canonical['presentation']['logoMode'] = 'inherit';
			foreach ($canonical['presentation']['story']['sections'] as &$section) $section['mediaIds'] = [];
			unset($section);
		}
		return json_encode(
			$canonical,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);
	}
}
