<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\MediaIndexMapper;
use OCA\ProofingGallery\Db\MediaIndexScanRepository;
use OCA\ProofingGallery\Db\PurgeRepository;
use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\MediaIndexService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;

$get = static fn (string $class) => \OC::$server->get($class);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$folderId = (int)($argv[1] ?? 0);
$expected = (int)($argv[2] ?? 0);
$assert($folderId > 0 && $expected > 500, 'Index restart verification requires more than 500 media files');
$gallery = $get(GalleryService::class)->create('admin', 'Index restart fixture', $folderId);
$id = (int)$gallery->getId();
$index = $get(MediaIndexService::class);
$rows = $get(MediaIndexMapper::class);
$scans = $get(MediaIndexScanRepository::class);
$db = $get(IDBConnection::class);
$config = $get(IConfig::class);
$trigger = 'pg_index_failure_' . $id;
$prefix = $config->getSystemValueString('dbtableprefix', 'oc_');
$snapshot = static function () use ($db, $id, $scans, $rows): array {
	$qb = $db->getQueryBuilder();
	$queue = QueryResult::rows($qb->select('*')->from('proofing_media_scan_queue')
		->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->orderBy('id', 'ASC')->executeQuery());
	return [$scans->find($id), $rows->countGallery($id), $queue];
};
try {
	$batch = $index->rebuild($gallery);
	$assert(!$batch['complete'] && $batch['indexed'] <= 499, 'First scan was not bounded to 500 examined entries');
	if ($config->getSystemValueString('dbtype') === 'mysql') {
		$before = $snapshot();
		// Only this isolated fixture can hit the trigger; all other galleries continue normally.
		$db->executeStatement('CREATE TRIGGER `' . $trigger . '` BEFORE INSERT ON `' . $prefix . 'proofing_media_index` FOR EACH ROW BEGIN IF NEW.gallery_id = ' . $id . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected index interruption'; END IF; END");
		try {
			$index->continueRebuild($gallery);
			throw new RuntimeException('Injected index interruption did not propagate');
		} catch (Throwable $error) { $assert(str_contains($error->getMessage(), 'Injected index interruption') && !str_contains($error->getMessage(), 'did not propagate'), 'Unexpected index failure: ' . $error->getMessage()); }
		$assert($snapshot() === $before, 'Failed index batch changed its rows, cursor or scan progress');
		$db->executeStatement('DROP TRIGGER `' . $trigger . '`');
	}
	for ($attempt = 0; $attempt < 10 && !$batch['complete']; $attempt++) {
		// Resolve again between batches to exercise persisted continuation state.
		$batch = (new MediaIndexService(
			$rows, $get(\OCA\ProofingGallery\Service\FolderService::class), $get(\OCA\ProofingGallery\Service\PolicyService::class),
			$get(\OCP\AppFramework\Utility\ITimeFactory::class), $get(\OCA\ProofingGallery\Service\MediaCursorCodec::class), $scans,
			$get(\OCP\BackgroundJob\IJobList::class), $get(\OCP\Lock\ILockingProvider::class), $get(\OCA\ProofingGallery\Service\MediaMetadataService::class),
			$get(\OCA\ProofingGallery\Db\MediaSortRepository::class), $get(\OCA\ProofingGallery\Service\FolderMediaReader::class), $db,
		))->continueRebuild($gallery);
	}
	$assert($batch['complete'] && $rows->countGallery($id) === $expected && $batch['indexed'] === $expected, 'Restarted scanner skipped or duplicated media');
	echo "Bounded scan rollback and persisted restart verified for $expected media files.\n";
} finally {
	if ($config->getSystemValueString('dbtype') === 'mysql') $db->executeStatement('DROP TRIGGER IF EXISTS `' . $trigger . '`');
	$purge = $get(PurgeRepository::class);
	foreach (PurgeRepository::TABLES as $table) while ($purge->deleteBatch($table, $id, 500) > 0) {}
}
