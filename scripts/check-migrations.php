<?php

declare(strict_types=1);

// Intended for the local studio container only. Nextcloud provides the DB prefix
// and connection through its public container, so this remains database-neutral.
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

$status = \OCP\Server::get(\OCA\ProofingGallery\Service\MigrationStatusService::class)->status();
echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($status['pending'] === [] && $status['missingTables'] === [] ? 0 : 3);
