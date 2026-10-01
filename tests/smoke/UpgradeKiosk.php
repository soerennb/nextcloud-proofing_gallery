<?php

declare(strict_types=1);

require '/var/www/html/lib/base.php';

$db = \OC::$server->get(\OCP\IDBConnection::class);
$repository = \OC::$server->get(\OCA\ProofingGallery\Db\KioskRepository::class);
$status = \OC::$server->get(\OCA\ProofingGallery\Service\MigrationStatusService::class)->status();
if ($status['pending'] !== [] || $status['missingTables'] !== []) throw new RuntimeException('Kiosk migration is incomplete');
foreach (['proofing_kiosk_events', 'proofing_kiosk_photos'] as $table) {
	if (!$db->tableExists($table)) throw new RuntimeException('Missing kiosk table: ' . $table);
}
$assertUnique = static function (callable $first, callable $duplicate) use ($db): void {
	$db->beginTransaction();
	$rejected = false;
	try {
		$first();
		$duplicate();
	} catch (\OCP\DB\Exception $exception) {
		if ($exception->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) throw $exception;
		$rejected = true;
	} finally { $db->rollBack(); }
	if (!$rejected) throw new RuntimeException('Kiosk identity uniqueness was not enforced');
};
$assertUnique(
	static function () use ($repository): void {
		$repository->reserveEvent('upgrade-kiosk', 'event-1', str_repeat('a', 64), ['title' => 'Upgrade'], time());
		$repository->reserveEvent('another-owner', 'event-1', str_repeat('a', 64), [], time());
		$event = $repository->event('upgrade-kiosk', 'event-1');
		if ($event === null || $event['state'] !== 'preparing' || $event['gallery_id'] !== null || $event['folder_id'] !== null) throw new RuntimeException('New event defaults are incorrect');
	},
	static fn () => $repository->reserveEvent('upgrade-kiosk', 'event-1', str_repeat('b', 64), [], time()),
);
$assertUnique(
	static function () use ($repository): void {
		$repository->reservePhoto(2147483640, 'df53f798-29cf-4f6d-b18a-859a9c1f7632', str_repeat('a', 64), time());
		$photo = $repository->photo(2147483640, 'df53f798-29cf-4f6d-b18a-859a9c1f7632');
		if ($photo === null || $photo['file_id'] !== null || $photo['stored_at'] !== null) throw new RuntimeException('New photo defaults are incorrect');
	},
	static fn () => $repository->reservePhoto(2147483640, 'df53f798-29cf-4f6d-b18a-859a9c1f7632', str_repeat('b', 64), time()),
);
echo "Kiosk upgrade installs both tables with durable account/event and gallery/photo uniqueness.\n";
