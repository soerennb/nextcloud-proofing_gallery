<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\QueryResult;
use OCP\IDBConnection;

final class MigrationStatusService {
	private const REQUIRED_TABLES = [
		'proofing_galleries',
		'proofing_guests',
		'proofing_purge_requests',
		'proofing_int_outbox',
		'proofing_retention_log',
		'proofing_link_roots',
		'proofing_event_waves',
		'proofing_pin_handoffs',
		'proofing_event_roots',
		'proofing_event_audit',
	];

	public function __construct(private IDBConnection $db) {
	}

	/** @return array{pending: list<string>, missingTables: list<string>} */
	public function status(): array {
		$applied = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('version')->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)));
		foreach (QueryResult::rows($qb->executeQuery()) as $row) $applied[] = (string)$row['version'];

		$bundled = array_map(
			static fn (string $file): string => substr(basename($file, '.php'), strlen('Version')),
			glob(dirname(__DIR__) . '/Migration/Version*.php') ?: [],
		);
		sort($bundled, SORT_STRING);

		$missingTables = [];
		foreach (self::REQUIRED_TABLES as $table) {
			if (!$this->db->tableExists($table)) $missingTables[] = $table;
		}

		return [
			'pending' => array_values(array_diff($bundled, $applied)),
			'missingTables' => $missingTables,
		];
	}
}
