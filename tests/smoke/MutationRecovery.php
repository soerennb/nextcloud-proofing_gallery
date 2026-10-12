<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Db\PublicLinkRootRepository;
use OCA\ProofingGallery\Db\PurgeRepository;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\PublicLinkManagerService;
use OCA\ProofingGallery\Service\PublicShareService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$get = static fn (string $class) => \OC::$server->get($class);
$get(IUserSession::class)->setUser($get(IUserManager::class)->get('admin'));
$root = $get(IRootFolder::class)->getUserFolder('admin');
$folder = $root->newFolder('__mutation-recovery-' . bin2hex(random_bytes(8)));
$galleries = $get(GalleryService::class);
$shares = $get(PublicShareService::class);
$files = $get(FolderService::class);
$db = $get(IDBConnection::class);
$gallery = null;
$stream = null;
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', true);
try {
	$anchorCount = static fn (): int => $root->nodeExists('.proofing-gallery/collections') ? count($root->get('.proofing-gallery/collections')->getDirectoryListing()) : 0;
	$beforeAnchors = $anchorCount();
	foreach ([['title' => ' ', 'settings' => []], ['title' => 'Invalid artwork', 'settings' => ['presentation' => ['coverFileId' => 999999999]]]] as $invalid) {
		try { $galleries->create('admin', $invalid['title'], null, $invalid['settings'], 'collection'); throw new RuntimeException('Invalid collection input was accepted'); }
		catch (InvalidArgumentException) {}
	}
	$assert($anchorCount() === $beforeAnchors, 'Invalid collection creation leaked an anchor');
	$original = $folder->newFile('photo.png', $png);
	$folder->newFile('photo.xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
	foreach (['Shared', 'Private'] as $name) $folder->newFolder($name)->newFile('photo.png', $png);
	$gallery = $galleries->create('admin', 'Mutation recovery fixture', $folder->getId(), ['mode' => 'collaboration'], 'folder', 'proofing');
	$gallery = $shares->publish($gallery, null, null, 'all');
	$links = $get(PublicLinkMapper::class);
	$rootRows = $get(PublicLinkRootRepository::class);
	$failedId = null; $failedToken = null; $failedAnchor = null;
	try {
		$get(PublicLinkManagerService::class)->create($gallery, PublicLinkConfiguration::fromArray([
			'name' => 'Rollback fixture', 'allowedRoots' => ['Shared', 'Private'], 'reviewEnabled' => true,
		]), persist: static function (int $id) use ($links, $rootRows, $db, $assert, &$failedId, &$failedToken, &$failedAnchor): never {
			$row = $links->find($id);
			$failedId = $id; $failedToken = $row->getToken(); $failedAnchor = $row->getScopeAnchorId();
			$assert($db->inTransaction(), 'Link and recipient persistence are not atomic');
			$assert(count($rootRows->findForLink($id)) === 2, 'Roots were not written before recipient persistence');
			throw new RuntimeException('Injected recipient persistence failure');
		});
		throw new RuntimeException('Link persistence failure did not propagate');
	} catch (RuntimeException $error) { $assert($error->getMessage() === 'Injected recipient persistence failure', 'Unexpected link error: ' . $error->getMessage()); }
	$assert($failedId !== null && count($links->findForGallery((int)$gallery->getId())) === 1, 'Partial link survived rollback');
	$assert($rootRows->findForLink($failedId) === [], 'Partial roots survived rollback');
	$assert($root->getById($failedAnchor) === [], 'Scope anchor leaked after rollback');
	try { $get(IManager::class)->getShareByToken($failedToken); throw new RuntimeException('Failed native share survived rollback'); }
	catch (ShareNotFound) {}
	$assert($get(IManager::class)->getShareByToken($gallery->getShareToken())->getNodeId() === $folder->getId(), 'Primary share was damaged by rollback');

	$stream = fopen('php://temp', 'w+b'); fwrite($stream, $png); rewind($stream);
	$staged = $files->stageMedia('admin', $folder->getId(), '', 'photo.png', $stream, 'image/png');
	$stagingName = $staged->getName(); $stagedId = $staged->getId();
	$expectedId = $original->getId(); $expectedEtag = $original->getEtag();
	try {
		$files->commitStagedMedia('admin', $folder->getId(), '', 'photo.png', $stagedId, $stagingName, 'overwrite', $expectedId, $expectedEtag, static function (): never { throw new RuntimeException('Injected receipt failure'); });
		throw new RuntimeException('Receipt failure did not propagate');
	} catch (RuntimeException $error) { $assert($error->getMessage() === 'Injected receipt failure', 'Unexpected upload error: ' . $error->getMessage()); }
	$assert($folder->get('photo.png')->getId() === $stagedId, 'Committed replacement was rolled back after failed receipt');
	$backupName = '.photo.png.replaced-' . $stagedId;
	$assert($folder->nodeExists($backupName) && $folder->nodeExists('.photo.xmp.replaced-' . $stagedId), 'Recovery backups were deleted before acknowledgment');
	$recovered = $files->commitStagedMedia('admin', $folder->getId(), '', 'photo.png', $stagedId, $stagingName, 'overwrite', $expectedId, $expectedEtag);
	$assert($recovered->id === $stagedId && !$folder->nodeExists($backupName), 'Retry did not reconcile the same committed file');

	// Simulate a process exit after moving the old original, before moving its XMP.
	$stream = fopen('php://temp', 'w+b'); fwrite($stream, $png); rewind($stream);
	$second = $files->stageMedia('admin', $folder->getId(), '', 'photo.png', $stream, 'image/png');
	$secondName = $second->getName(); $secondId = $second->getId();
	$current = $folder->get('photo.png'); $etag = $current->getEtag();
	$folder->newFile('photo.xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
	$current->move($folder->getPath() . '/.photo.png.replaced-' . $secondId);
	$resumed = $files->commitStagedMedia('admin', $folder->getId(), '', 'photo.png', $secondId, $secondName, 'overwrite', $stagedId, $etag);
	$assert($resumed->id === $secondId && !$folder->nodeExists('photo.xmp'), 'Interrupted paired replacement did not resume safely');
	echo "Native link rollback and both upload interruption boundaries verified.\n";
} finally {
	if (is_resource($stream)) fclose($stream);
	if ($gallery !== null) {
		$shares->revoke($get(GalleryMapper::class)->find((int)$gallery->getId()));
		$purge = $get(PurgeRepository::class);
		foreach (PurgeRepository::TABLES as $table) while ($purge->deleteBatch($table, (int)$gallery->getId(), 500) > 0) {}
	}
	$folder->delete();
}
