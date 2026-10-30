<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });

use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\PublicLinkManagerService;
use OCP\Server;

$config = Server::get(\OCP\IConfig::class);
$root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('admin');
$db = Server::get(\OCP\IDBConnection::class);
$seed = ($argv[1] ?? '') === 'seed';
if ($seed) {
	Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get('admin'));
	$folder = $root->newFolder('ServerUpgrade');
	$photo = $folder->newFile('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', true));
	$file = $folder->newFile('original.txt', 'server upgrade original');
	$gallery = Server::get(GalleryService::class)->create('admin', 'Server upgrade gallery', $folder->getId());
	$q = $db->getQueryBuilder();
	$values = ['gallery_id' => $gallery->getId(), 'file_id' => $photo->getId(), 'actor_uid' => 'admin', 'kind' => 'rating', 'value' => '5', 'created_at' => time(), 'updated_at' => time()];
	$q->insert('proofing_feedback')->values(array_map(static fn ($v) => $q->createNamedParameter($v), $values))->executeStatement();
	$gallery = Server::get(\OCA\ProofingGallery\Service\PublicShareService::class)->publish($gallery, null, null, 'all');
	Server::get(PublicLinkManagerService::class)->create($gallery, PublicLinkConfiguration::fromArray(['name' => 'Server upgrade link']));
}
$snapshot = [];
foreach (['proofing_galleries', 'proofing_public_links', 'proofing_feedback', 'share'] as $table) {
	$q = $db->getQueryBuilder();
	$snapshot[$table] = QueryResult::rows($q->select('*')->from($table)->orderBy('id')->executeQuery());
}
$snapshot['photo'] = hash('sha256', $root->get('ServerUpgrade/photo.png')->getContent());
$snapshot['original'] = $root->get('ServerUpgrade/original.txt')->getContent();
$encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
if ($seed) {
	Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get('admin'));
	$config->setAppValue('proofing_gallery', 'serverUpgradeSnapshot', $encoded);
} else {
	if ((int)explode('.', $config->getSystemValueString('version'))[0] !== 35) throw new RuntimeException('Server did not upgrade to 35');
	if ($config->getAppValue('proofing_gallery', 'serverUpgradeSnapshot') !== $encoded) throw new RuntimeException('Server upgrade changed gallery data');
	if (!Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('proofing_gallery')) throw new RuntimeException('App disabled after upgrade');
}
echo $seed ? "Server upgrade fixtures seeded.\n" : "Server upgrade preserves galleries, links, feedback and original files.\n";
