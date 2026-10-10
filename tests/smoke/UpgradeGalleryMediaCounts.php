<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Service\GalleryMediaCountService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\BackgroundJob\BackfillGalleryMediaCountsJob;

$db = \OC::$server->get(\OCP\IDBConnection::class);
$q = $db->getQueryBuilder();
$id = (int)$q->select('id')->from('proofing_galleries')->where($q->expr()->eq('slug', $q->createNamedParameter('upgrade-existing')))->executeQuery()->fetchOne();
if (($argv[1] ?? 'verify') === 'seed') {
	$q = $db->getQueryBuilder();
	$q->insert('proofing_summaries')->values([
		'gallery_id' => $q->createNamedParameter($id), 'folder_id' => $q->createNamedParameter(1), 'folder_etag' => $q->createNamedParameter('legacy'),
		'media_total' => $q->createNamedParameter(999), 'scanned_at' => $q->createNamedParameter(time()),
	])->executeStatement();
	echo "Seeded incorrect legacy overview count.\n";
	exit(0);
}
$counts = \OC::$server->get(GalleryMediaCountRepository::class);
$summary = GalleryMediaCountService::present($counts->find($id));
if ($summary['total'] !== 0 || $summary['imageCount'] !== null) throw new RuntimeException('Legacy folder totals were adopted as media counts');
$page = \OC::$server->get(GalleryService::class)->listV2('admin', 100, null, false, 'Upgrade sentinel', null, null, null, null, true, 'updated');
foreach ($page['items'] as $item) if ($item['id'] === $id && $item['mediaSummary']['total'] !== 0) throw new RuntimeException('Overview still uses the old mixed total');
$job = \OC::$server->get(BackfillGalleryMediaCountsJob::class);
$method = new ReflectionMethod($job, 'run');
$config = \OC::$server->get(\OCP\IConfig::class);
for ($i = 0; $i < 100 && $config->getAppValue('proofing_gallery', 'mediaCountsProjectionV1State', '') !== 'complete'; $i++) $method->invoke($job, []);
if ($config->getAppValue('proofing_gallery', 'mediaCountsProjectionV1State', '') !== 'complete') throw new RuntimeException('Count backfill did not resume to completion');
$q = $db->getQueryBuilder();
$missing = (int)$q->select($q->func()->count('g.id'))->from('proofing_galleries', 'g')->leftJoin('g', 'proofing_media_counts', 'c', $q->expr()->eq('g.id', 'c.gallery_id'))
	->where($q->expr()->isNull('c.gallery_id'))->executeQuery()->fetchOne();
if ($missing !== 0) throw new RuntimeException('Count backfill skipped galleries');
\OC::$server->get(\OCA\ProofingGallery\BackgroundJob\ReconcileGalleryMediaCountsJob::class);
\OC::$server->get(\OCA\ProofingGallery\BackgroundJob\RebuildGalleryMediaCountsJob::class);
$root = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder('admin');
$folder = $root->newFolder('UpgradeCount-' . bin2hex(random_bytes(6)));
$gallery = null;
try {
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
	$folder->newFolder('Album')->newFile('image.png', $png);
	$folder->newFile('.hidden.png', $png);
	$gallery = \OC::$server->get(GalleryService::class)->create('admin', 'Upgrade count transaction', (int)$folder->getId());
	$galleryId = (int)$gallery->getId();
	$db->beginTransaction();
	try {
		if (!$counts->enqueue($galleryId, 'alias-test', 1, 'Album') || $counts->enqueue($galleryId, 'alias-test', 1, 'Alias')) throw new RuntimeException('Scan alias deduplication failed');
		$counts->clearQueue($galleryId);
		$db->commit();
	} catch (Throwable $error) { $db->rollBack(); throw $error; }
	$service = \OC::$server->get(GalleryMediaCountService::class);
	if (!$service->run($gallery) || $service->summary($galleryId)['imageCount'] !== 1) throw new RuntimeException('Upgraded count transaction failed');
} finally {
	if ($gallery !== null) {
		$purge = \OC::$server->get(\OCA\ProofingGallery\Db\PurgeRepository::class);
		foreach (\OCA\ProofingGallery\Db\PurgeRepository::TABLES as $table) $purge->deleteBatch($table, (int)$gallery->getId(), 1000);
	}
	$folder->delete();
}
echo "Count migration ignores incorrect legacy totals and schedules recoverable counts for every gallery.\n";
