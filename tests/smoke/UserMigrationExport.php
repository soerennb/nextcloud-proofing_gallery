<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

$uid = $argv[1] ?? 'admin';
$user = \OCP\Server::get(\OCP\IUserManager::class)->get($uid);
if ($user === null) throw new RuntimeException('Smoke-test user not found');

$destination = new class implements \OCP\UserMigration\IExportDestination {
	/** @var array<string, string> */
	public array $files = [];
	public function addFileContents(string $path, string $content): void { $this->files[$path] = $content; }
	public function addFileAsStream(string $path, $stream): void { $this->files[$path] = stream_get_contents($stream); }
	public function copyFolder(\OCP\Files\Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void { throw new LogicException('Unexpected file export'); }
	public function setMigratorVersions(array $versions): void {}
	public function close(): void {}
};

$migrator = \OCP\Server::get(\OCA\ProofingGallery\UserMigration\ProofingGalleryMigrator::class);
$migrator->export($user, $destination, new \Symfony\Component\Console\Output\NullOutput());
$manifest = json_decode($destination->files['proofing_gallery/manifest.json'] ?? '', true, flags: JSON_THROW_ON_ERROR);
$inspect = static function (mixed $value) use (&$inspect): void {
	if (!is_array($value)) return;
	foreach ($value as $key => $child) {
		if (is_string($key) && in_array($key, ['shareToken', 'password', 'guestId', 'ownerUid', 'folderId'], true)) {
			throw new RuntimeException('Non-portable field exported: ' . $key);
		}
		if (is_string($key) && in_array($key, ['logoFileId', 'heroFileId', 'instanceLogoAssetId'], true) && $child !== null) {
			throw new RuntimeException('Instance-bound asset reference exported: ' . $key);
		}
		$inspect($child);
	}
};
$inspect($manifest);
printf("User migration export is portable: %d galleries, %d presets, %d templates.\n",
	count($manifest['galleries'] ?? []), count($manifest['presets'] ?? []), count($manifest['invitationTemplates'] ?? []));
