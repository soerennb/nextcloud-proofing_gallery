<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Replace only the previous untouched gallery accent default. */
final class Version000128Date20260819 extends SimpleMigrationStep {
	private const OLD_DEFAULT = '#1f6f8b';
	private const NEW_DEFAULT = '#E85D4A';

	public function __construct(private IDBConnection $db, private IConfig $config) {
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$lastId = 0;
		do {
			$rows = $this->galleryBatch($lastId);
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				$this->migrateGallery($row);
			}
		} while (count($rows) === 100);
		$this->migrateConfiguredDefaults();
	}

	/** @return list<array<string, mixed>> */
	private function galleryBatch(int $lastId): array {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'settings')
			->from('proofing_galleries')
			->where($select->expr()->gt('id', $select->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults(100);
		$result = $select->executeQuery();
		$rows = [];
		while (($row = QueryResult::row($result)) !== false) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	/** @param array<string, mixed> $row */
	private function migrateGallery(array $row): void {
		$settings = json_decode((string)$row['settings'], true);
		if (!is_array($settings)) return;

		$changed = false;
		foreach ([['presentation', 'accentColor'], ['appearance', 'accentColor']] as [$section, $key]) {
			if (!isset($settings[$section]) || !is_array($settings[$section])) continue;
			$value = $settings[$section][$key] ?? null;
			if (!is_string($value) || strtolower($value) !== self::OLD_DEFAULT) continue;
			$settings[$section][$key] = self::NEW_DEFAULT;
			$changed = true;
		}
		if (!$changed) return;

		$encoded = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$update = $this->db->getQueryBuilder();
		$update->update('proofing_galleries')
			->set('settings', $update->createNamedParameter($encoded))
			->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function migrateConfiguredDefaults(): void {
		foreach (['instanceSettingsV2', 'galleryDefaults'] as $key) {
			$raw = $this->config->getAppValue(Application::APP_ID, $key, '');
			$value = json_decode($raw, true);
			if (!is_array($value)) continue;
			$paths = $key === 'instanceSettingsV2'
				? [['branding', 'accentColor']]
				: [['presentation', 'accentColor'], ['appearance', 'accentColor']];
			$changed = false;
			foreach ($paths as [$section, $field]) {
				if (!isset($value[$section]) || !is_array($value[$section])) continue;
				$accent = $value[$section][$field] ?? null;
				if (!is_string($accent) || strtolower($accent) !== self::OLD_DEFAULT) continue;
				$value[$section][$field] = self::NEW_DEFAULT;
				$changed = true;
			}
			if ($changed) {
				$this->config->setAppValue(Application::APP_ID, $key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			}
		}
	}
}
