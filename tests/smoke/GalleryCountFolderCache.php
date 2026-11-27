<?php

declare(strict_types=1);

// Subtree count cache (proofing_count_folders): every recount must match an independent walk of
// the folder, and unchanged folders must be carried over as cache hits instead of being walked.

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\FolderCountRepository;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Db\PurgeRepository;
use OCA\ProofingGallery\Service\GalleryMediaCountService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCP\Files\File;
use OCP\Files\Folder;

$server = \OC::$server;
$root = $server->get(\OCP\Files\IRootFolder::class)->getUserFolder('admin');
$service = $server->get(GalleryMediaCountService::class);
$rows = $server->get(GalleryMediaCountRepository::class);
$folderRows = $server->get(FolderCountRepository::class);
$types = $server->get(MediaTypePolicy::class);
$assert = static function (bool $value, string $context): void { if (!$value) throw new RuntimeException($context); };
$jpeg = file_get_contents('/var/www/html/custom_apps/proofing_gallery/tests/e2e/fixtures/kiosk.jpg');
$video = file_get_contents('/var/www/html/custom_apps/proofing_gallery/tests/e2e/fixtures/count-video.mp4');

/** Independent oracle: a plain recursive listing without hidden entries. */
$oracle = static function (Folder $folder) use (&$oracle, $types): array {
	$images = 0; $videos = 0;
	foreach ($folder->getDirectoryListing() as $node) {
		if (str_starts_with($node->getName(), '.')) continue;
		if ($node instanceof Folder) { [$i, $v] = $oracle($node); $images += $i; $videos += $v; continue; }
		if ($node instanceof File && $types->supports($node)) {
			if (str_starts_with($node->getMimeType(), 'video/')) $videos++; else $images++;
		}
	}
	return [$images, $videos];
};

$folder = $root->newFolder('ProofingGalleryCountCache-' . bin2hex(random_bytes(6)));
$gallery = null;
try {
	$a = $folder->newFolder('A');
	foreach (['a1', 'a2', 'a3'] as $name) $a->newFile("$name.jpg", $jpeg);
	$deep = $a->newFolder('Deep');
	foreach (['d1', 'd2'] as $name) $deep->newFile("$name.jpg", $jpeg);
	$b = $folder->newFolder('B');
	foreach (['b1', 'b2'] as $name) $b->newFile("$name.jpg", $jpeg);
	$b->newFile('clip.mp4', $video);
	$c = $folder->newFolder('C');
	$c->newFile('c1.jpg', $jpeg);
	$c->newFolder('.hidden')->newFile('h.jpg', $jpeg);
	$folder->newFile('top.jpg', $jpeg);

	$gallery = $server->get(GalleryService::class)->create('admin', 'Subtree count cache test', (int)$folder->getId());
	$id = (int)$gallery->getId();

	/** Recounts until done and compares the published totals with the oracle. */
	$check = static function (string $step) use ($service, $gallery, $id, $folder, $oracle, $assert): void {
		for ($batch = 0; $batch < 20 && !$service->run($gallery); $batch++) {}
		$summary = $service->summary($id);
		[$images, $videos] = $oracle($folder);
		$assert($summary['countState'] === 'ready', "$step: count did not finish");
		$assert($summary['imageCount'] === $images && $summary['videoCount'] === $videos,
			"$step: counted {$summary['imageCount']}/{$summary['videoCount']}, oracle $images/$videos");
	};
	/** @return array<int, bool> folder ID => taken from the cache in the latest generation */
	$hits = static function () use ($rows, $folderRows, $id): array {
		$generation = (string)$rows->find($id)['generation'];
		$result = [];
		foreach ($folderRows->generation($id, $generation) as $folderId => $row) $result[$folderId] = in_array($row['hit'], [true, 1, '1', 't', 'true'], true);
		return $result;
	};

	$check('initial count');
	$assert(!in_array(true, $hits(), true), 'The first count reported cache hits');

	$deep->newFile('d3.jpg', $jpeg);
	$check('add deep in A');
	$state = $hits();
	$assert(($state[(int)$b->getId()] ?? false) && ($state[(int)$c->getId()] ?? false), 'Unchanged folders B and C were walked again');
	$assert(($state[(int)$a->getId()] ?? true) === false && ($state[(int)$deep->getId()] ?? true) === false, 'Changed folders A and Deep were taken from the cache');

	$b->get('b1.jpg')->delete();
	$check('delete in B');
	$state = $hits();
	$assert(($state[(int)$a->getId()] ?? false) && !($state[(int)$b->getId()] ?? true), 'Only B should be recounted after a deletion in B');

	$a->move($folder->getPath() . '/A-renamed');
	$a = $folder->get('A-renamed');
	$check('rename A');

	$deep = $a->get('Deep');
	$deep->move($b->getPath() . '/Deep');
	$check('move Deep from A into B');

	$a->get('a1.jpg')->move($c->getPath() . '/.hidden/a1.jpg');
	$check('move a file into a hidden folder');

	$cId = (int)$c->getId();
	$folder->get('C')->delete();
	$check('delete C');
	$assert(!isset($folderRows->all($id)[$cId]), 'The row of the deleted folder C was kept');

	$folder->get('top.jpg')->delete();
	$check('delete a root file');
	$state = $hits();
	$assert(($state[(int)$a->getId()] ?? false) && ($state[(int)$b->getId()] ?? false), 'A root-only change walked unchanged subfolders');
	// Deep sits below the cache hit B and was not walked; its row must stay for the next change in B.
	$assert(isset($folderRows->all($id)[(int)$deep->getId()]), 'The row below a cache hit was removed');
	$b->get('Deep')->newFile('d4.jpg', $jpeg);
	$check('add below a previously cached subtree');

	echo "Subtree count cache matched the oracle through add, delete, rename, move and hidden moves, with unchanged folders taken from the cache and orphaned rows removed.\n";
} finally {
	if ($gallery !== null) {
		$purge = $server->get(PurgeRepository::class);
		foreach (PurgeRepository::TABLES as $table) while ($purge->deleteBatch($table, (int)$gallery->getId(), 1000) >= 1000) {}
	}
	$folder->delete();
}
