<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

$users = \OCP\Server::get(\OCP\IUserManager::class);
$uid = 'proofing-migration-' . bin2hex(random_bytes(4));
$user = $users->createUser($uid, bin2hex(random_bytes(16)));
if ($user === false) throw new RuntimeException('Could not create smoke-test user');

try {
	$photos = \OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->newFolder('Photos');
	$photos->newFile('pixel.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true));
	$manifest = json_encode([
		'schemaVersion' => 1,
		'galleries' => [
			['id' => 1, 'title' => 'Portable draft', 'purpose' => 'proofing', 'sourcePath' => 'Photos', 'settings' => []],
			['id' => 2, 'title' => 'Missing source', 'purpose' => 'custom', 'sourcePath' => 'Missing', 'settings' => []],
			['id' => 4, 'title' => 'Portable collection', 'purpose' => 'showcase', 'sourceType' => 'collection', 'settings' => [],
				'items' => [['sourceGalleryId' => 1, 'filePath' => 'Photos/pixel.gif']]],
		],
		'presets' => [['id' => 3, 'name' => 'Portable preset', 'settings' => []]],
		'invitationTemplates' => [['name' => 'Portable invitation', 'body' => 'Review {gallery}: {url}']],
		'preferences' => ['designPresetId' => 3, 'savedViews' => []],
	], JSON_THROW_ON_ERROR);
	$source = new class($manifest, $uid) implements \OCP\UserMigration\IImportSource {
		public function __construct(private string $manifest, private string $uid) {}
		public function getFileContents(string $path): string { return $this->manifest; }
		public function getFileAsStream(string $path) { throw new LogicException('Unexpected stream import'); }
		public function getFolderListing(string $path): array { return []; }
		public function pathExists(string $path): bool { return $path === 'proofing_gallery/manifest.json'; }
		public function copyToFolder(\OCP\Files\Folder $destination, string $sourcePath): void { throw new LogicException('Unexpected file import'); }
		public function getMigratorVersions(): array { return ['proofing_gallery' => 1]; }
		public function getMigratorVersion(string $migrator): ?int { return $migrator === 'proofing_gallery' ? 1 : null; }
		public function getOriginalUid(): string { return $this->uid; }
		public function close(): void {}
	};
	$migrator = \OCP\Server::get(\OCA\ProofingGallery\UserMigration\ProofingGalleryMigrator::class);
	$output = new \Symfony\Component\Console\Output\NullOutput();
	$migrator->import($user, $source, $output);
	$migrator->import($user, $source, $output);

	$galleries = \OCP\Server::get(\OCA\ProofingGallery\Db\GalleryMapper::class)->findAllOwned($uid, 10, 0, false);
	$presets = \OCP\Server::get(\OCA\ProofingGallery\Db\PresetMapper::class)->findAllOwned($uid);
	$templates = \OCP\Server::get(\OCA\ProofingGallery\Db\InvitationTemplateMapper::class)->findAllOwned($uid);
	if (count($galleries) !== 4 || count($presets) !== 2 || count($templates) !== 2) throw new RuntimeException('Import was not additive');
	foreach ($galleries as $gallery) {
		if ($gallery->getStatus() !== 'draft' || $gallery->getShareToken() !== null) throw new RuntimeException('Imported gallery was exposed');
	}
	if ($presets[0]->getName() === $presets[1]->getName()) throw new RuntimeException('Preset collision was not resolved');
} finally {
	$user->delete();
}
echo "User migration import is additive; missing sources are skipped and drafts remain private.\n";
