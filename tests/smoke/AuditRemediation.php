<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\EventWaveRepository;
use OCA\ProofingGallery\Db\PurgeRepository;
use OCA\ProofingGallery\Db\UploadRepository;
use OCA\ProofingGallery\Db\SemanticIndexRepository;
use OCA\ProofingGallery\Db\MediaIndexMapper;
use OCA\ProofingGallery\Dto\CullingFilter;
use OCA\ProofingGallery\Dto\MediaIndexQuery;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\UploadService;
use OCA\ProofingGallery\Service\PrivateGuestInboxService;
use OCA\ProofingGallery\Service\CullingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;

$get = static fn (string $class) => \OC::$server->get($class);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$get(IUserSession::class)->setUser($get(IUserManager::class)->get('admin'));
$root = $get(IRootFolder::class)->getUserFolder('admin');
$folder = $root->newFolder('__audit-' . bin2hex(random_bytes(6)));
$outside = $root->newFolder('__audit-outside-' . bin2hex(random_bytes(6)));
$db = $get(IDBConnection::class); $config = $get(IConfig::class);
$gallery = null; $uploadIds = []; $trigger = null; $candidateBase = null;
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', true);
try {
	$gallery = $get(GalleryService::class)->create('admin', 'Audit recovery fixture', (int)$folder->getId(), ['mode' => 'collaboration', 'delivery' => ['guestUploads' => true]], 'folder', 'proofing');
	$id = (int)$gallery->getId();
	$candidateBase = 100000000 + $id * 1000;
	$guest = $get(GuestService::class)->create($gallery, 'Audit guest')['guest'];
	$uploads = $get(UploadService::class); $rows = $get(UploadRepository::class);
	$session = $uploads->initiate($gallery, $guest, 'private.png', 'image/png', strlen($png));
	$uploadIds[] = $session['id'];
	$uploads->putChunk($gallery, $guest, $session['id'], 0, $png);
	if ($config->getSystemValueString('dbtype') === 'mysql') {
		$prefix = $config->getSystemValueString('dbtableprefix', 'oc_');
		$trigger = 'pg_audit_chunks_' . $id;
		$db->executeStatement('CREATE TRIGGER `' . $trigger . '` BEFORE UPDATE ON `' . $prefix . 'proofing_uploads` FOR EACH ROW BEGIN IF NEW.gallery_id = ' . $id . " AND NEW.chunks_cleaned = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected chunk cleanup receipt failure'; END IF; END");
	}
	$receipt = $uploads->finalize($gallery, $guest, $session['id']);
	if ($trigger !== null) {
		$assert(!(bool)$rows->find($id, $session['id'])['chunks_cleaned'], 'Failed chunk cleanup lost its retry receipt');
		$db->executeStatement('DROP TRIGGER `' . $trigger . '`'); $trigger = null;
		$get(PrivateGuestInboxService::class)->cleanup();
	}
	$assert((bool)$rows->find($id, $session['id'])['chunks_cleaned'], 'Completed upload chunks were not durably cleaned');
	$assert($receipt === $uploads->finalize($gallery, $guest, $session['id']), 'Lost finalize response duplicated the receipt');
	$assert($uploads->status($gallery, $guest, $session['id'])['status'] === 'awaiting_review', 'Status depends on already deleted chunks');
	$assert(!$folder->nodeExists('.proofing-gallery-inbox') && !$folder->nodeExists('private.png'), 'Unapproved media entered the native share tree');
	$assert($get(PrivateGuestInboxService::class)->read($session['id'])->getContent() === $png, 'Private payload changed');
	$publicRow = $uploads->listForGallery($gallery)[0];
	$assert(!array_key_exists('private_key', $publicRow), 'Private storage key leaked into an API projection');

	// A failed receipt leaves the stable committed file ID available for reconciliation.
	if ($config->getSystemValueString('dbtype') === 'mysql') {
		$trigger = 'pg_audit_receipt_' . $id;
		$prefix = $config->getSystemValueString('dbtableprefix', 'oc_');
		$db->executeStatement('CREATE TRIGGER `' . $trigger . '` BEFORE UPDATE ON `' . $prefix . 'proofing_uploads` FOR EACH ROW BEGIN IF NEW.gallery_id = ' . $id . " AND NEW.status = 'accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected inbox receipt failure'; END IF; END");
		try { $uploads->moderate($gallery, $session['id'], true); throw new RuntimeException('Injected receipt failure did not propagate'); }
		catch (Throwable $error) { $assert(str_contains($error->getMessage(), 'Injected inbox receipt failure') && !str_contains($error->getMessage(), 'did not propagate'), 'Unexpected acceptance failure: ' . $error->getMessage()); }
		$committedId = (int)$folder->get('private.png')->getId();
		$assert($rows->find($id, $session['id'])['status'] === 'awaiting_review', 'Acceptance transaction did not roll back');
		$db->executeStatement('DROP TRIGGER `' . $trigger . '`'); $trigger = null;
	} else $committedId = null;
	$uploads->moderate($gallery, $session['id'], true);
	$uploads->moderate($gallery, $session['id'], true);
	$accepted = $rows->find($id, $session['id']);
	$assert($accepted['status'] === 'accepted' && $accepted['private_key'] === null, 'Acceptance receipt or cleanup failed');
	if ($committedId !== null) $assert((int)$accepted['file_id'] === $committedId, 'Acceptance retry created a second visible file');
	$assert(count($folder->getDirectoryListing()) === 1, 'Acceptance left duplicate or hidden staged files');
	$rejected = $uploads->initiate($gallery, $guest, 'reject.png', 'image/png', strlen($png));
	$uploadIds[] = $rejected['id']; $uploads->putChunk($gallery, $guest, $rejected['id'], 0, $png);
	$uploads->finalize($gallery, $guest, $rejected['id']);
	$uploads->moderate($gallery, $rejected['id'], false); $uploads->moderate($gallery, $rejected['id'], false);
	$assert(!$folder->nodeExists('reject.png'), 'Rejected payload was delivered');

	$foreign = $outside->newFile('foreign.png', $png);
	try { $get(CullingService::class)->forGalleryFiles($gallery, [(int)$foreign->getId()]); throw new RuntimeException('Foreign culling ID was accepted'); }
	catch (\OCA\ProofingGallery\Exception\FolderAccessException) {}

	$index = $get(MediaIndexMapper::class);
	for ($position = 0; $position < 220; $position++) $index->upsert($id, $candidateBase + $position, (int)$folder->getId(), sprintf('%03d.png', $position), 0, 'audit', time(), ['name' => sprintf('%03d.png', $position), 'mimeType' => 'image/png', 'size' => 1, 'mtime' => time(), 'etag' => 'audit']);
	$qb = $db->getQueryBuilder(); $qb->insert('proofing_media_cull')->values(array_map(static fn ($value) => $qb->createNamedParameter($value), ['owner_uid' => 'admin', 'file_id' => $candidateBase + 219, 'rating' => 5, 'pick_state' => 'pick', 'color' => 'red', 'revision' => 1, 'source' => 'app', 'updated_at' => time()]))->executeStatement();
	$query = MediaIndexQuery::fromInput($id, 'admin', 200, '', '', 'name', 'asc', 0, culling: new CullingFilter(5, 'pick', 'red', [$candidateBase + 219]));
	$assert($index->countFiltered($query) === 1 && $index->page($query)[0]->getFileId() === $candidateBase + 219, 'Owner filters were applied after the first 200 files');
	$empty = MediaIndexQuery::fromInput($id, 'admin', 200, '', '', 'name', 'asc', 0, culling: new CullingFilter(fileIds: []));
	$assert($index->countFiltered($empty) === 0, 'Empty semantic matches broadened the query');
	$unrated = MediaIndexQuery::fromInput($id, 'admin', 200, '', '', 'name', 'asc', 0, culling: new CullingFilter(0, 'none', 'none'));
	$assert($index->countFiltered($unrated) === 219, 'Missing culling rows lost their default state');
	$semantic = $get(SemanticIndexRepository::class);
	$semantic->snapshot($id, 'audit', [$candidateBase + 2, $candidateBase + 219, $candidateBase + 5], 100);
	$assert($semantic->candidates($id, 'audit', 1, 1) === [$candidateBase + 219], 'Semantic snapshot cursor changed membership');
	$assert($semantic->candidateCount($id, 'audit') === 3, 'Semantic snapshot lost members');

	// A review cannot advance if its durable side-effect receipt fails.
	$gallery = $get(\OCA\ProofingGallery\Db\GalleryMapper::class)->find($id);
	$gallery = $get(\OCA\ProofingGallery\Service\PublicShareService::class)->publish($gallery, null, null, 'all');
	$reviewLink = $get(\OCA\ProofingGallery\Service\PublicLinkManagerService::class)->create($gallery, \OCA\ProofingGallery\Dto\PublicLinkConfiguration::fromArray(['name' => 'Audit review', 'reviewEnabled' => true]));
	$link = $get(\OCA\ProofingGallery\Db\PublicLinkMapper::class)->find((int)$reviewLink['id']);
	$actor = \OCA\ProofingGallery\Domain\CollaborationActor::guest($guest);
	$collaboration = $get(\OCA\ProofingGallery\Service\CollaborationService::class);
	$collaboration->saveSelection($gallery, $link, $actor, 'Audit draft', '', [(int)$accepted['file_id']]);
	$reviews = $get(\OCA\ProofingGallery\Service\ReviewWorkflowService::class);
	$reviewRows = $get(\OCA\ProofingGallery\Db\ReviewRoundRepository::class);
	$reviews->publicState($gallery, $link, $actor);
	if ($config->getSystemValueString('dbtype') === 'mysql') {
		$trigger = 'pg_audit_review_' . $id;
		$db->executeStatement('CREATE TRIGGER `' . $trigger . '` BEFORE INSERT ON `' . $prefix . 'proofing_int_outbox` FOR EACH ROW BEGIN IF NEW.gallery_id = ' . $id . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected review outbox failure'; END IF; END");
		try { $reviews->submit($gallery, $link, $actor); throw new RuntimeException('Review side-effect failure did not propagate'); }
		catch (Throwable $error) { $assert(str_contains($error->getMessage(), 'Injected review outbox failure') && !str_contains($error->getMessage(), 'did not propagate'), 'Unexpected review failure: ' . $error->getMessage()); }
		$assert($reviewRows->current((int)$link->getId())['status'] === 'awaiting_feedback', 'Failed outbox committed its review status');
		$db->executeStatement('DROP TRIGGER `' . $trigger . '`'); $trigger = null;
	}
	$reviews->submit($gallery, $link, $actor);
	$reviews->transition('admin', $id, (int)$link->getId(), 'approve');
	$assert($reviewRows->current((int)$link->getId())['status'] === 'approved', 'Recovered review did not finish');

	// Export inspection stops at the first exceeded file limit and ignores hidden payloads.
	$previousLimit = $config->getAppValue('proofing_gallery', 'maxGalleryDownloadFiles', '');
	try {
		$config->setAppValue('proofing_gallery', 'maxGalleryDownloadFiles', '1');
		$folder->newFile('export-second.png', $png); $folder->newFile('export-third.png', $png);
		$folder->newFolder('.private')->newFile('hidden.png', $png);
		$context = $get(\OCA\ProofingGallery\Service\PublicShareContextResolver::class)->resolve($gallery->getShareToken());
		$inspection = $get(\OCA\ProofingGallery\Service\PublicGalleryDownloadService::class)->inspect($context);
		$assert(!$inspection['available'] && $inspection['reason'] === 'too_many_files' && $inspection['fileCount'] === 2,
			'Export limit was checked after enumerating every file: ' . json_encode(array_intersect_key($inspection, array_flip(['available', 'reason', 'fileCount', 'maxFiles', 'totalBytes'])), JSON_THROW_ON_ERROR));
		foreach ($inspection['entries'] as $entry) $assert(!str_contains($entry['path'], '.private'), 'Export inspection included private payloads');
	} finally {
		if ($previousLimit === '') $config->deleteAppValue('proofing_gallery', 'maxGalleryDownloadFiles'); else $config->setAppValue('proofing_gallery', 'maxGalleryDownloadFiles', $previousLimit);
		$folder->get('export-second.png')->delete(); $folder->get('export-third.png')->delete(); $folder->get('.private')->delete();
	}

	// Freeze membership across multiple semantic jobs, even if files change later.
	$previousSettings = $config->getAppValue('proofing_gallery', 'instanceSettingsV2', '');
	$previousBatch = $config->getAppValue('proofing_gallery', 'semanticBatchSize', '');
	$search = $get(\OCA\ProofingGallery\Service\SemanticSearchService::class);
	try {
		$instance = $get(\OCA\ProofingGallery\Service\PolicyService::class)->instanceSettings();
		$instance['semantic']['provider'] = 'local';
		$config->setAppValue('proofing_gallery', 'instanceSettingsV2', json_encode($instance, JSON_THROW_ON_ERROR));
		$config->setAppValue('proofing_gallery', 'semanticBatchSize', '1');
		$second = $folder->newFile('semantic-second.png', $png);
		$get(\OCA\ProofingGallery\Service\MediaIndexService::class)->rebuild($gallery);
		$queued = $search->enqueue($gallery);
		$generation = $config->getAppValue('proofing_gallery', 'semantic.pending.' . $id);
		$firstBatch = $search->indexBatch($gallery, $generation, $queued['provider'], $queued['model'], 0, 0);
		$assert($firstBatch['indexed'] === 1 && $firstBatch['total'] === 2 && !$firstBatch['complete'], 'Semantic batch did not use the frozen candidate snapshot');
		$second->delete(); $folder->newFile('semantic-late.png', $png);
		$get(\OCA\ProofingGallery\Service\MediaIndexService::class)->rebuild($gallery);
		$lastBatch = $search->indexBatch($gallery, $generation, $queued['provider'], $queued['model'], 1, 0);
		$assert($lastBatch['complete'] && $lastBatch['indexed'] === 0 && $lastBatch['total'] === 2, 'Source changes shifted semantic continuation membership');
		$assert(count($search->search($gallery, 'private')) === 1, 'Completed semantic generation was not activated');
	} finally {
		$search->delete($gallery);
		if ($previousSettings === '') $config->deleteAppValue('proofing_gallery', 'instanceSettingsV2'); else $config->setAppValue('proofing_gallery', 'instanceSettingsV2', $previousSettings);
		if ($previousBatch === '') $config->deleteAppValue('proofing_gallery', 'semanticBatchSize'); else $config->setAppValue('proofing_gallery', 'semanticBatchSize', $previousBatch);
	}

	$waves = $get(EventWaveRepository::class);
	$waveId = $waves->create($id, 'releasing', ['sharedRoots' => [], 'policy' => [], 'expiresAt' => null, 'releaseAt' => null, 'sendInvitations' => false], [['setupKey' => null, 'folderId' => $folder->getId(), 'folderPath' => 'Private', 'groupRoots' => [], 'name' => 'Audit recipient', 'emailCipher' => null, 'locale' => null, 'pinCipher' => null]], 1000);
	$assert(in_array($waveId, $waves->stalledWaveIds(2600), true), 'Terminated queued publication was not discoverable');
	$first = $waves->claim($waveId, 25, 1000)[0];
	$assert($waves->claim($waveId, 25, 1001) === [], 'Active event lease was stolen');
	$second = $waves->claim($waveId, 25, 1301)[0];
	$assert($first['claim_token'] !== $second['claim_token'], 'Recovered event lease reused the old fencing token');
	try { $waves->published((int)$first['id'], 99999, 'not_requested', null, 1302, $first['claim_token']); throw new RuntimeException('Stale event worker wrote a publication receipt'); }
	catch (RuntimeException $error) { $assert($error->getMessage() === 'Event publication claim was superseded', $error->getMessage()); }
	for ($attempt = 2; $attempt < 5; $attempt++) $waves->claim($waveId, 25, 1000 + $attempt * 301);
	$assert($waves->claim($waveId, 25, 2600) === [] && $waves->progress($waveId)['failed'] === 1, 'Event lease recovery did not stop after five attempts');
	$assert($waves->retryFailed($waveId, 2601) === 1 && count($waves->claim($waveId, 25, 2602)) === 1, 'Explicit event retry could not recover an exhausted lease');
	echo "Private upload receipts, interrupted acceptance, culling boundaries, filtered pagination, semantic snapshots and event leases verified. Atomic review outbox recovery verified.\n";
} finally {
	if ($trigger !== null) $db->executeStatement('DROP TRIGGER IF EXISTS `' . $trigger . '`');
	foreach ($uploadIds as $uploadId) $get(PrivateGuestInboxService::class)->delete($uploadId);
	if ($gallery !== null) {
		foreach ($get(\OCA\ProofingGallery\Db\PublicLinkMapper::class)->findForGallery((int)$gallery->getId()) as $cleanupLink) {
			try { $get(\OCP\Share\IManager::class)->deleteShare($get(\OCP\Share\IManager::class)->getShareByToken($cleanupLink->getToken())); } catch (\OCP\Share\Exceptions\ShareNotFound) {}
		}
		$purge = $get(PurgeRepository::class); foreach (PurgeRepository::TABLES as $table) while ($purge->deleteBatch($table, (int)$gallery->getId(), 500) > 0) {} }
	if ($candidateBase !== null) { $qb = $db->getQueryBuilder(); $qb->delete('proofing_media_cull')->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter('admin')))->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($candidateBase + 219, IQueryBuilder::PARAM_INT)))->executeStatement(); }
	$folder->delete(); $outside->delete();
}
