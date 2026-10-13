<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
try {
	$options = getopt('', ['ids:', 'storage:', 'execute', 'help']);
	if (isset($options['execute']) && !in_array('--execute', $argv, true)) throw new InvalidArgumentException('Execution requires the explicit --execute flag');
	if (isset($options['help'])) {
		fwrite(STDOUT, "Usage: php cleanup-orphan-metadata.php --ids=/absolute/list.json --storage=ID [--execute]\nDefault: dry-run. JSON must contain positive integer file IDs. NEXTCLOUD_ROOT defaults to /var/www/html.\n");
		exit(0);
	}
	if (!is_array($options) || !is_string($options['ids'] ?? null) || !is_string($options['storage'] ?? null)
		|| !ctype_digit($options['storage']) || (int)$options['storage'] < 1) throw new InvalidArgumentException('Both --ids and a positive --storage are required; use --help');
	$path = $options['ids'];
	if (!is_file($path) || filesize($path) > 4000000) throw new InvalidArgumentException('ID list must be a readable JSON file of at most 4 MB');
	$ids = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
	if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100000) throw new InvalidArgumentException('ID list must contain at most 100000 positive integers');
	foreach ($ids as $id) if (!is_int($id) || $id < 1) throw new InvalidArgumentException('File IDs must be positive integers');
	define('OC_CONSOLE', 1);
	require rtrim(getenv('NEXTCLOUD_ROOT') ?: '/var/www/html', '/') . '/lib/base.php';
	$service = \OC::$server->get(\OCA\ProofingGallery\Service\OrphanMetadataCleanupService::class);
	fwrite(STDOUT, json_encode($service->run((int)$options['storage'], $ids, isset($options['execute'])), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
} catch (Throwable $error) {
	fwrite(STDERR, $error->getMessage() . "\n");
	exit(1);
}
