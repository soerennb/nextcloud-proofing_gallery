<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PurgeRepository;
use OCA\ProofingGallery\Service\GalleryMediaCountService;
use OCA\ProofingGallery\Service\GalleryMediaCountInvalidator;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Service\MediaIndexService;
use OCA\ProofingGallery\Service\GalleryReadinessService;

$server = \OC::$server;
$root = $server->get(\OCP\Files\IRootFolder::class)->getUserFolder('admin');
$folder = $root->newFolder('ProofingGalleryCount-' . bin2hex(random_bytes(6)));
$service = $server->get(GalleryMediaCountService::class);
$rows = $server->get(GalleryMediaCountRepository::class);
$invalidator = $server->get(GalleryMediaCountInvalidator::class);
$galleries = $server->get(GalleryService::class);
$config = $server->get(\OCP\IConfig::class);
$oldLimit = $config->getAppValue('proofing_gallery', 'maxIndexedMedia', '25000');
$created = [];
$assert = static function (bool $value, string $context): void { if (!$value) throw new RuntimeException($context); };
$finish = static function ($gallery) use ($service, $assert): void {
	for ($batch = 0; $batch < 20; $batch++) if ($service->run($gallery)) return;
	$assert(false, 'Count did not finish in bounded batches');
};
try {
	$album = $folder->newFolder('Album');
	$jpeg = file_get_contents('/var/www/html/custom_apps/proofing_gallery/tests/e2e/fixtures/kiosk.jpg');
	for ($i = 0; $i < 550; $i++) $album->newFile("image$i.jpg", $jpeg);
	$album->newFile('clip.mp4', file_get_contents('/var/www/html/custom_apps/proofing_gallery/tests/e2e/fixtures/count-video.mp4'));
	$album->newFile('.hidden.jpg', $jpeg);
	$folder->newFolder('.private')->newFile('private.jpg', $jpeg);
	$folder->newFolder('Empty');
	$folder->newFile('notes.txt', 'unsupported');
	$gallery = $galleries->create('admin', 'Bounded media count test', (int)$folder->getId());
	$created[] = $gallery;
	$assert(!$service->run($gallery), 'A 551-media source must require more than one batch');
	$partial = $rows->find((int)$gallery->getId());
	$assert($partial['image_count'] === null && (int)$partial['working_images'] < 500, 'Partial counts must not become final numbers');
	$generation = (string)$partial['generation'];
	$invalidator->invalidate((int)$gallery->getId());
	$assert(!$service->run($gallery), 'An invalidated partial generation must restart');
	$assert($rows->find((int)$gallery->getId())['generation'] !== $generation, 'Invalidation reused an obsolete generation');
	$finish($gallery);
	$summary = $service->summary((int)$gallery->getId());
	$assert($summary['imageCount'] === 550 && $summary['videoCount'] === 1 && $summary['total'] === 551, 'Recursive media counts include folders, hidden or unsupported media');
	$config->setAppValue('proofing_gallery', 'maxIndexedMedia', '100');
	$index = $server->get(MediaIndexService::class)->rebuild($gallery);
	$assert($index['indexed'] === 100 && $index['truncated'], 'Index cap was not retained');
	$assert($service->summary((int)$gallery->getId())['total'] === 551, 'Index cap truncated the count');
	$revision = (int)$rows->find((int)$gallery->getId())['revision'];
	$invalidator->invalidate((int)$gallery->getId());
	$assert(!$rows->publish((int)$gallery->getId(), (string)$rows->find((int)$gallery->getId())['generation'], $revision, 999, 0, null, time()), 'A late invalidation was overwritten');
	$assert($service->summary((int)$gallery->getId())['total'] === 551, 'Invalidation discarded the previous completed count');
	$album->get('image0.jpg')->delete();
	$finish($gallery);
	$assert($service->summary((int)$gallery->getId())['imageCount'] === 549, 'Deletion was not reflected');

	$collection = $galleries->create('admin', 'Available collection count test', null, sourceType: 'collection');
	$created[] = $collection;
	$collections = $server->get(CollectionService::class);
	$first = $album->get('image1.jpg'); $second = $album->get('image2.jpg');
	$collections->replace($collection, 1, [['sourceGalleryId' => (int)$gallery->getId(), 'fileId' => (int)$first->getId()], ['sourceGalleryId' => (int)$gallery->getId(), 'fileId' => (int)$second->getId()]]);
	$finish($collection);
	$assert($service->summary((int)$collection->getId())['imageCount'] === 2, 'Collection membership was not counted');
	$second->delete();
	$invalidator->invalidate((int)$gallery->getId());
	$finish($collection);
	$assert($service->summary((int)$collection->getId())['imageCount'] === 1, 'Missing collection member was counted');
	$first->delete();
	$assert(!$service->hasMedia($collection), 'An old positive count allowed publishing an empty collection');
	$assert(!$server->get(GalleryReadinessService::class)->evaluate($collection, 'admin')['ready'], 'Readiness used configured rather than available collection media');
	$collectionAnchor = $root->getById($collection->getFolderId())[0];
	$collectionAnchor->delete();
	$gallery->setStatus('archived'); $server->get(GalleryMapper::class)->update($gallery);
	$invalidator->invalidate((int)$gallery->getId()); $finish($gallery);
	$assert($service->summary((int)$gallery->getId())['imageCount'] === 547, 'Archived gallery count did not follow its source');
	$staleGallery = clone $gallery;
	$rebound = $folder->newFolder('Rebound');
	$gallery->setFolderId((int)$rebound->getId());
	$server->get(GalleryMapper::class)->update($gallery);
	$invalidator->invalidate((int)$gallery->getId(), true);
	$finish($staleGallery);
	$assert($service->summary((int)$gallery->getId())['total'] === 0, 'A worker holding the old source published an obsolete count after rebind');
	$album->get('image3.jpg')->move($rebound->getPath() . '/moved.jpg');
	$assert($rows->find((int)$gallery->getId())['state'] === 'pending', 'Moving media across folders did not invalidate the destination');
	$finish($gallery);
	$assert($service->summary((int)$gallery->getId())['imageCount'] === 1, 'Moved media was not counted');
	$hidden = $rebound->newFolder('.hidden');
	$rebound->get('moved.jpg')->move($hidden->getPath() . '/moved.jpg');
	$assert(!$service->hasMedia($gallery), 'A witness moved into a hidden branch still allowed publishing');
	$finish($gallery);
	$assert($service->summary((int)$gallery->getId())['total'] === 0, 'Moving media into a hidden branch retained its count');
	echo "Recursive counts, bounded resume, invalidation, index cap, collections, readiness and archives verified.\n";
} finally {
	$config->setAppValue('proofing_gallery', 'maxIndexedMedia', $oldLimit);
	$purge = $server->get(PurgeRepository::class);
	foreach (array_reverse($created) as $gallery) foreach (PurgeRepository::TABLES as $table) while ($purge->deleteBatch($table, (int)$gallery->getId(), 1000) >= 1000) {}
	$folder->delete();
}
