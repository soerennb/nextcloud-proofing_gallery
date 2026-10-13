<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\UploadRepository;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PrivateGuestInboxService;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;

$get = static fn (string $class) => \OC::$server->get($class);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$config = $get(IConfig::class);
$mode = $argv[1] ?? 'verify';
if ($mode === 'seed') {
	$root = $get(IRootFolder::class)->getUserFolder('admin');
	$folder = $root->newFolder('__upgrade-private-inbox-' . bin2hex(random_bytes(6)));
	$inbox = $folder->newFolder('.proofing-gallery-inbox');
	$file = $inbox->newFile('unapproved.jpg', 'legacy private payload');
	$gallery = $get(GalleryService::class)->create('admin', 'Private inbox upgrade', (int)$folder->getId());
	$guest = $get(GuestService::class)->create($gallery, 'Upgrade guest')['guest'];
	$id = bin2hex(random_bytes(20));
	$uploads = $get(UploadRepository::class);
	$uploads->insert((int)$gallery->getId(), (int)$guest->getId(), $id, 'unapproved.jpg', 'image/jpeg', 22, time());
	$db = $get(IDBConnection::class); $qb = $db->getQueryBuilder();
	$qb->update('proofing_uploads')->set('status', $qb->createNamedParameter('awaiting_review'))
		->set('file_id', $qb->createNamedParameter((int)$file->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
		->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($id)))->executeStatement();
	$config->setAppValue('proofing_gallery', 'test.privateInbox', json_encode(['galleryId' => $gallery->getId(), 'uploadId' => $id, 'fileId' => $file->getId()], JSON_THROW_ON_ERROR));
	echo "Seeded legacy native-share inbox.\n";
} else {
	$fixture = json_decode($config->getAppValue('proofing_gallery', 'test.privateInbox'), true, flags: JSON_THROW_ON_ERROR);
	$row = $get(UploadRepository::class)->find((int)$fixture['galleryId'], $fixture['uploadId']);
	$assert($row !== null && $row['private_key'] === $fixture['uploadId'] && $row['file_id'] === null && $row['legacy_file_id'] === null, 'Upgrade did not finish its private receipt');
	$assert($get(IRootFolder::class)->getUserFolder('admin')->getById((int)$fixture['fileId']) === [], 'Unapproved legacy file remains reachable through native shares');
	$assert($get(PrivateGuestInboxService::class)->read($fixture['uploadId'])->getContent() === 'legacy private payload', 'Private inbox content changed during migration');
	$assert($get(PrivateGuestInboxService::class)->migrateBatch() === 0, 'Upgrade cannot be resumed idempotently');
	echo "Legacy guest inbox migrated privately and resumably.\n";
}
