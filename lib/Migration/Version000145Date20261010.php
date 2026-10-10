<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Migration;

use Closure;
use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Preserve existing artwork while introducing independently editable card covers. */
final class Version000145Date20261010 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db, private IConfig $config) {
	}

	/** @param array<string, mixed> $options */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (['proofing_galleries', 'proofing_presets'] as $table) {
			$after = 0;
			do {
				$qb = $this->db->getQueryBuilder();
				$rows = QueryResult::rows($qb->select('id', 'settings')->from($table)
					->where($qb->expr()->gt('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
					->orderBy('id', 'ASC')->setMaxResults(200)->executeQuery());
				foreach ($rows as $row) {
					$after = (int)$row['id'];
					try {
						$decoded = json_decode((string)$row['settings'], true, flags: JSON_THROW_ON_ERROR);
						if (!is_array($decoded)) throw new \InvalidArgumentException('Expected a settings object');
						$settings = GallerySettings::fromArray($decoded)->canonical();
					} catch (\JsonException|\InvalidArgumentException $exception) {
						$output->warning('Artwork migration retained invalid settings in ' . $table . ' #' . $after . ': ' . $exception->getMessage());
						continue;
					}
					if ($table === 'proofing_presets') {
						$settings['presentation']['coverFileId'] = null;
						$settings['presentation']['heroFileId'] = null;
						if ($settings['presentation']['heroSource'] === 'custom') $settings['presentation']['heroSource'] = 'cover';
					}
					$update = $this->db->getQueryBuilder();
					$update->update($table)->set('settings', $update->createNamedParameter(json_encode($settings, JSON_THROW_ON_ERROR)))
						->where($update->expr()->eq('id', $update->createNamedParameter($after, IQueryBuilder::PARAM_INT)))->executeStatement();
				}
			} while (count($rows) === 200);
		}
		$defaults = json_decode($this->config->getAppValue('proofing_gallery', 'galleryDefaults', '{}'), true);
		if (is_array($defaults) && $defaults !== []) {
			try {
				$settings = GallerySettings::fromArray($defaults)->canonical();
				$settings['presentation']['coverFileId'] = null;
				$settings['presentation']['heroFileId'] = null;
				if ($settings['presentation']['heroSource'] === 'custom') $settings['presentation']['heroSource'] = 'cover';
				$this->config->setAppValue('proofing_gallery', 'galleryDefaults', json_encode($settings, JSON_THROW_ON_ERROR));
			} catch (\InvalidArgumentException $exception) {
				$output->warning('Artwork migration retained invalid gallery defaults: ' . $exception->getMessage());
			}
		}
	}
}
