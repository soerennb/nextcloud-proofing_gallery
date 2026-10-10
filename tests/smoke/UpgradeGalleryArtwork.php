<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });

use OCA\ProofingGallery\Db\QueryResult;

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$config = \OCP\Server::get(\OCP\IConfig::class);
if (($argv[1] ?? 'verify') === 'seed') {
	$q = $db->getQueryBuilder();
	$gallery = QueryResult::rows($q->select('*')->from('proofing_galleries')->where($q->expr()->eq('slug', $q->createNamedParameter('upgrade-existing')))->executeQuery())[0];
	unset($gallery['id']);
	foreach (['hero' => ['heroFileId' => 42, 'openerStyle' => 'cinematic'], 'automatic' => ['heroFileId' => null, 'openerStyle' => 'minimal']] as $name => $presentation) {
		$gallery['slug'] = 'upgrade-artwork-' . $name;
		$gallery['share_token'] = null;
		$gallery['status'] = 'draft';
		$gallery['settings'] = json_encode(['schemaVersion' => 13, 'presentation' => $presentation], JSON_THROW_ON_ERROR);
		$q = $db->getQueryBuilder();
		$q->insert('proofing_galleries')->values(array_map(static fn ($value) => $q->createNamedParameter($value), $gallery))->executeStatement();
	}
	$config->setAppValue('proofing_gallery', 'galleryDefaults', json_encode(['presentation' => ['heroFileId' => 42]], JSON_THROW_ON_ERROR));
	echo "Seeded legacy gallery artwork.\n";
	exit(0);
}
$q = $db->getQueryBuilder();
$rows = QueryResult::rows($q->select('slug', 'settings')->from('proofing_galleries')->where($q->expr()->like('slug', $q->createNamedParameter('upgrade-artwork-%')))->executeQuery());
if (count($rows) !== 2) throw new RuntimeException('Artwork fixtures were lost');
foreach ($rows as $row) {
	$settings = json_decode($row['settings'], true, flags: JSON_THROW_ON_ERROR);
	$hero = $row['slug'] === 'upgrade-artwork-hero';
	$p = $settings['presentation'];
	if ($settings['schemaVersion'] !== 14 || $p['heroSource'] !== ($hero ? 'custom' : 'cover')
		|| $p['heroFileId'] !== ($hero ? 42 : null) || $p['coverFileId'] !== ($hero ? 42 : null)
		|| $p['openerStyle'] !== ($hero ? 'cinematic' : 'minimal')) throw new RuntimeException('Artwork migration changed existing presentation: ' . $row['slug']);
}
$defaults = json_decode($config->getAppValue('proofing_gallery', 'galleryDefaults'), true, flags: JSON_THROW_ON_ERROR);
if ($defaults['presentation']['coverFileId'] !== null || $defaults['presentation']['heroFileId'] !== null || $defaults['presentation']['heroSource'] !== 'cover') throw new RuntimeException('Defaults retain gallery-specific artwork');
echo "Gallery artwork upgrade preserves custom heroes, seeds card covers and keeps automatic covers independent.\n";
