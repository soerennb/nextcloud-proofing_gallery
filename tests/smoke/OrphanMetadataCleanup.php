<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\QueryResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IConfig;

$db = \OC::$server->get(IDBConnection::class);
$config = \OC::$server->get(IConfig::class);
$folder = \OC::$server->get(IRootFolder::class)->getUserFolder('admin')->newFolder('__metadata-cleanup-' . bin2hex(random_bytes(4)));
$file = $folder->newFile('keep.txt', 'Keep this live fixture');
$liveId = (int)$file->getId();
$storage = (int)$file->getStorage()->getCache()->getNumericStorageId();
$base = random_int(900000000, 910000000);
$ids = range($base, $base + 8499);
$list = tempnam(sys_get_temp_dir(), 'pg-metadata-ids-');
$trigger = null;
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$run = static function (bool $execute) use ($list, $storage): array {
	$arguments = [PHP_BINARY, '/tmp/proofing-cleanup-orphan-metadata.php', '--ids=' . $list, '--storage=' . $storage];
	if ($execute) $arguments[] = '--execute';
	$process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process)) throw new RuntimeException('Cleanup CLI could not start');
	fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
	$error = stream_get_contents($pipes[2]); fclose($pipes[2]);
	return [proc_close($process), $output, $error];
};
$count = static function (string $table, array $batch) use ($db): int {
	$qb = $db->getQueryBuilder();
	return (int)QueryResult::value($qb->select($qb->func()->count())->from($table)->where($qb->expr()->in('file_id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery());
};
try {
	$db->beginTransaction();
	foreach ([...$ids, $liveId] as $id) {
		if ($count('files_metadata', [$id]) === 0) {
			$qb = $db->getQueryBuilder();
			$values = ['file_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT), 'json' => $qb->createNamedParameter('{}'), 'sync_token' => $qb->createNamedParameter('audit'), 'last_update' => $qb->createFunction('CURRENT_TIMESTAMP')];
			$qb->insert('files_metadata')->values($values)->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->insert('files_metadata_index')->values(['file_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT), 'meta_key' => $qb->createNamedParameter('audit-cleanup'), 'meta_value_int' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)])->executeStatement();
	}
	$db->commit();
	file_put_contents($list, json_encode([...$ids, $liveId, $ids[0]], JSON_THROW_ON_ERROR));
	[$status, $output, $error] = $run(false);
	$assert($status === 0, 'Dry-run CLI failed: ' . $error);
	$summary = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	$assert($summary['eligible'] === 8500 && $summary['cleaned'] === 0 && $summary['existing'] === 1 && $summary['unique'] === 8501, 'Dry-run did not classify duplicates/live files correctly');
	$assert($count('files_metadata', array_slice($ids, 0, 500)) === 500, 'Dry-run deleted metadata');
	$expectedRemaining = 8500;
	if ($config->getSystemValueString('dbtype') === 'sqlite3') {
		$trigger = 'pg_metadata_interruption';
		$prefix = $config->getSystemValueString('dbtableprefix', 'oc_');
		$db->executeStatement('CREATE TRIGGER ' . $trigger . ' BEFORE DELETE ON ' . $prefix . 'files_metadata WHEN OLD.file_id = ' . ($base + 501) . " BEGIN SELECT RAISE(ABORT, 'Injected orphan cleanup interruption'); END");
		[$status, , $error] = $run(true);
		$assert($status === 1 && str_contains($error, 'Metadata cleanup incomplete'), 'Core swallowed failure was reported as successful cleanup');
		$assert($count('files_metadata', array_slice($ids, 0, 500)) === 0, 'First committed cleanup batch was lost');
		$db->executeStatement('DROP TRIGGER ' . $trigger); $trigger = null;
		$expectedRemaining = array_sum(array_map(static fn (array $batch): int => $count('files_metadata', $batch), array_chunk($ids, 500)));
		$assert($expectedRemaining === 8000, 'Interrupted cleanup did not roll back its failed batch');
	}
	[$status, $output, $error] = $run(true);
	$assert($status === 0, 'Execute CLI failed: ' . $error);
	$summary = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	$assert($summary['existing'] === 1 && $summary['cleaned'] === $expectedRemaining, 'Interrupted cleanup did not resume from retained metadata');
	foreach (array_chunk($ids, 500) as $batch) {
		$assert($count('files_metadata', $batch) === 0 && $count('files_metadata_index', $batch) === 0, 'Orphan payload or index survived cleanup');
	}
	$assert($count('files_metadata', [$liveId]) === 1 && $file->getContent() === 'Keep this live fixture', 'Cleanup touched an existing file or its metadata');
	[$status, $output, $error] = $run(true);
	$assert($status === 0 && json_decode($output, true, 512, JSON_THROW_ON_ERROR)['cleaned'] === 0, 'Repeated cleanup was not idempotent: ' . $error);
	echo "Metadata CLI dry-run, 8500 orphan IDs, duplicate/live protection, bounded deletion and resume verified.\n";
} finally {
	if ($db->inTransaction()) $db->rollBack();
	if ($trigger !== null) $db->executeStatement('DROP TRIGGER IF EXISTS ' . $trigger);
	foreach (array_chunk($ids, 500) as $batch) foreach (['files_metadata', 'files_metadata_index'] as $table) {
		$qb = $db->getQueryBuilder(); $qb->delete($table)->where($qb->expr()->in('file_id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
	}
	unlink($list); $folder->delete();
}
