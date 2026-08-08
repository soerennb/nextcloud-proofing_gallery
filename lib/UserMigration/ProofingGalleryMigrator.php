<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\UserMigration;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Db\InvitationTemplateMapper;
use OCA\ProofingGallery\Db\PresetMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Service\InvitationTemplateService;
use OCA\ProofingGallery\Service\PresetService;
use OCA\ProofingGallery\Service\UserPreferenceService;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\ISizeEstimationMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use OCP\UserMigration\UserMigrationException;
use Symfony\Component\Console\Output\OutputInterface;

/** Portable, privacy-minimal transfer of owner configuration as additive drafts. */
final class ProofingGalleryMigrator implements IMigrator, ISizeEstimationMigrator {
	use TMigratorBasicVersionHandling;

	private const PATH = Application::APP_ID . '/manifest.json';
	private const MAX_MANIFEST_BYTES = 16 * 1024 * 1024;

	public function __construct(
		private GalleryMapper $galleries,
		private PresetMapper $presets,
		private InvitationTemplateMapper $templates,
		private GalleryService $galleryService,
		private PresetService $presetService,
		private InvitationTemplateService $templateService,
		private UserPreferenceService $preferences,
		private CollectionRepository $collectionRows,
		private CollectionService $collections,
		private IRootFolder $root,
		private IL10N $l10n,
	) {
	}

	public function getId(): string { return Application::APP_ID; }
	public function getDisplayName(): string { return $this->l10n->t('Proofing Gallery'); }
	public function getDescription(): string {
		return $this->l10n->t('Gallery drafts, design presets, invitation templates, and personal Proofing Gallery settings');
	}

	public function getEstimatedExportSize(IUser $user): int {
		$uid = $user->getUID();
		$count = $this->galleries->countOwned($uid, false) + $this->galleries->countOwned($uid, true)
			+ $this->presets->countOwned($uid) + $this->templates->countOwned($uid);
		return max(1, (int)ceil(($count * 4096 + 8192) / 1024));
	}

	public function export(IUser $user, IExportDestination $destination, OutputInterface $output): void {
		$output->writeln('Exporting Proofing Gallery configuration…');
		try {
			$data = [
				'schemaVersion' => 1,
				'galleries' => $this->exportGalleries($user->getUID(), $output),
				'presets' => array_map(static fn ($preset): array => [
					'id' => (int)$preset->getId(), 'name' => $preset->getName(),
					'settings' => self::portableSettings(json_decode($preset->getSettings(), true, flags: JSON_THROW_ON_ERROR)),
				], $this->presets->findAllOwned($user->getUID())),
				'invitationTemplates' => array_map(static fn ($template): array => [
					'name' => $template->getName(), 'body' => $template->getBody(),
				], $this->templates->findAllOwned($user->getUID())),
				'preferences' => $this->portablePreferences($this->preferences->get($user->getUID())),
			];
			$destination->addFileContents(self::PATH, json_encode($data, JSON_THROW_ON_ERROR));
		} catch (\Throwable $exception) {
			throw new UserMigrationException('Could not export Proofing Gallery configuration', 0, $exception);
		}
	}

	public function import(IUser $user, IImportSource $source, OutputInterface $output): void {
		if ($source->getMigratorVersion($this->getId()) === null || !$source->pathExists(self::PATH)) {
			$output->writeln('No Proofing Gallery configuration to import.');
			return;
		}
		$raw = $source->getFileContents(self::PATH);
		if (strlen($raw) > self::MAX_MANIFEST_BYTES) throw new UserMigrationException('Proofing Gallery manifest is too large');
		try {
			$data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
			if (!is_array($data) || ($data['schemaVersion'] ?? null) !== 1) throw new \UnexpectedValueException('Unsupported manifest');
			$this->importData($user->getUID(), $data, $output);
		} catch (UserMigrationException $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			throw new UserMigrationException('Could not import Proofing Gallery configuration', 0, $exception);
		}
	}

	/** @return list<array<string, mixed>> */
	private function exportGalleries(string $uid, OutputInterface $output): array {
		$result = [];
		$userFolder = $this->root->getUserFolder($uid);
		foreach ([false, true] as $archived) {
			for ($offset = 0; ; $offset += 500) {
				$batch = $this->galleries->findAllOwned($uid, 500, $offset, $archived);
				foreach ($batch as $gallery) {
					if ($gallery->getSourceType() === 'collection') {
						$result[] = [
							'id' => (int)$gallery->getId(), 'title' => $gallery->getTitle(), 'purpose' => $gallery->getPurpose(),
							'sourceType' => 'collection', 'items' => $this->exportCollectionItems((int)$gallery->getId(), $userFolder, $output),
							'settings' => self::portableSettings(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR)),
						];
						continue;
					}
					$nodes = $userFolder->getById($gallery->getFolderId());
					$folder = current(array_filter($nodes, static fn ($node): bool => $node instanceof Folder));
					$path = $folder instanceof Folder ? $userFolder->getRelativePath($folder->getPath()) : null;
					if ($path === null) {
						$output->writeln('Skipping gallery with an unavailable source folder: “' . $gallery->getTitle() . '”.');
						continue;
					}
					$result[] = [
						'id' => (int)$gallery->getId(), 'title' => $gallery->getTitle(), 'purpose' => $gallery->getPurpose(), 'sourceType' => 'folder',
						'sourcePath' => $path, 'settings' => self::portableSettings(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR)),
					];
				}
				if (count($batch) < 500) break;
			}
		}
		return $result;
	}

	/** @return list<array{sourceGalleryId: int, filePath: string}> */
	private function exportCollectionItems(int $galleryId, Folder $userFolder, OutputInterface $output): array {
		$items = [];
		foreach ($this->collectionRows->items($galleryId) as $row) {
			$nodes = $userFolder->getById((int)$row['file_id']);
			$file = current(array_filter($nodes, static fn ($node): bool => $node instanceof File));
			$path = $file instanceof File ? $userFolder->getRelativePath($file->getPath()) : null;
			if ($path === null) {
				$output->writeln('Skipping an unavailable collection item.');
				continue;
			}
			$items[] = ['sourceGalleryId' => (int)$row['source_gallery_id'], 'filePath' => $path];
		}
		return $items;
	}

	/** @param array<string, mixed> $data */
	private function importData(string $uid, array $data, OutputInterface $output): void {
		$presetIds = [];
		foreach ($this->boundedList($data['presets'] ?? [], 1000) as $row) {
			try {
				$name = $this->uniqueName($uid, (string)($row['name'] ?? ''), $this->presets);
				$preset = $this->presetService->create($uid, $name, self::portableSettings($this->object($row['settings'] ?? [])));
				$presetIds[(int)($row['id'] ?? 0)] = (int)$preset->getId();
			} catch (\Throwable $exception) { $output->writeln('Skipping an invalid Proofing Gallery preset.'); }
		}

		$galleryIds = [];
		$userFolder = $this->root->getUserFolder($uid);
		$galleryRows = $this->boundedList($data['galleries'] ?? [], 10000);
		foreach (array_filter($galleryRows, static fn (array $row): bool => ($row['sourceType'] ?? 'folder') === 'folder') as $row) {
			try {
				$path = trim((string)($row['sourcePath'] ?? ''), '/');
				$folder = $path === '' ? $userFolder : $userFolder->get($path);
				if (!$folder instanceof Folder || !$folder->isReadable()) throw new \UnexpectedValueException('Source folder is unavailable');
				$settings = self::portableSettings($this->object($row['settings'] ?? []));
				$settings['lifecycle']['enabled'] = false;
				$settings['lifecycle']['retentionHandoff'] = false;
				$gallery = $this->galleryService->create($uid, (string)($row['title'] ?? ''), $folder->getId(), $settings, 'folder', (string)($row['purpose'] ?? 'custom'));
				$galleryIds[(int)($row['id'] ?? 0)] = (int)$gallery->getId();
			} catch (\Throwable $exception) { $output->writeln('Skipping a gallery whose source or settings are unavailable.'); }
		}
		foreach (array_filter($galleryRows, static fn (array $row): bool => ($row['sourceType'] ?? 'folder') === 'collection') as $row) {
			try {
				$settings = self::portableSettings($this->object($row['settings'] ?? []));
				$settings['lifecycle']['enabled'] = false;
				$settings['lifecycle']['retentionHandoff'] = false;
				$collection = $this->galleryService->create($uid, (string)($row['title'] ?? ''), null, $settings, 'collection', (string)($row['purpose'] ?? 'custom'));
				$items = [];
				foreach ($this->boundedList($row['items'] ?? [], CollectionService::MAX_ITEMS) as $item) {
					$sourceId = $galleryIds[(int)($item['sourceGalleryId'] ?? 0)] ?? null;
					$path = trim((string)($item['filePath'] ?? ''), '/');
					try { $file = $path === '' ? null : $userFolder->get($path); } catch (\Throwable) { $file = null; }
					if ($sourceId === null || !$file instanceof File || !$file->isReadable()) {
						$output->writeln('Skipping a collection item whose source is unavailable.');
						continue;
					}
					$items[] = ['sourceGalleryId' => $sourceId, 'fileId' => $file->getId()];
				}
				$this->collections->replace($collection, 1, $items);
				$galleryIds[(int)($row['id'] ?? 0)] = (int)$collection->getId();
			} catch (\Throwable $exception) { $output->writeln('Skipping a collection whose sources or settings are unavailable.'); }
		}

		foreach ($this->boundedList($data['invitationTemplates'] ?? [], 1000) as $row) {
			try {
				$name = $this->uniqueName($uid, (string)($row['name'] ?? ''), $this->templates);
				$this->templateService->create($uid, $name, (string)($row['body'] ?? ''));
			} catch (\Throwable $exception) { $output->writeln('Skipping an invalid invitation template.'); }
		}

		$preferences = $this->object($data['preferences'] ?? []);
		if (isset($preferences['designPresetId'])) $preferences['designPresetId'] = $presetIds[(int)$preferences['designPresetId']] ?? null;
		$preferences['savedViews'] = array_values(array_filter(array_map(static function (mixed $view) use ($galleryIds): ?array {
			if (!is_array($view) || !isset($galleryIds[(int)($view['galleryId'] ?? 0)])) return null;
			$view['galleryId'] = $galleryIds[(int)$view['galleryId']];
			return $view;
		}, is_array($preferences['savedViews'] ?? null) ? $preferences['savedViews'] : [])));
		unset($preferences['parentFolder'], $preferences['schemaVersion']);
		try { $this->preferences->save($uid, $preferences); } catch (\Throwable) { $output->writeln('Some personal gallery settings could not be imported.'); }
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private static function portableSettings(array $settings): array {
		$canonical = GallerySettings::fromArray($settings)->canonical();
		$canonical['presentation']['logoFileId'] = null;
		$canonical['presentation']['heroFileId'] = null;
		$canonical['presentation']['instanceLogoAssetId'] = null;
		return $canonical;
	}

	/**
	 * @param array<string, mixed> $preferences
	 * @return array<string, mixed>
	 */
	private function portablePreferences(array $preferences): array {
		unset($preferences['parentFolder'], $preferences['schemaVersion']);
		return $preferences;
	}

	/** @return list<array<string, mixed>> */
	private function boundedList(mixed $value, int $limit): array {
		if (!is_array($value) || !array_is_list($value) || count($value) > $limit) throw new UserMigrationException('Invalid Proofing Gallery manifest list');
		return array_values(array_filter($value, 'is_array'));
	}

	/** @return array<string, mixed> */
	private function object(mixed $value): array {
		if (!is_array($value) || array_is_list($value)) throw new UserMigrationException('Invalid Proofing Gallery manifest object');
		return $value;
	}

	private function uniqueName(string $uid, string $name, PresetMapper|InvitationTemplateMapper $mapper): string {
		$name = trim($name);
		if ($name === '') return 'Imported';
		if (!$mapper->nameExists($uid, $name)) return mb_substr($name, 0, 120);
		for ($copy = 2; $copy < 1000; $copy++) {
			$candidate = mb_substr($name, 0, 105) . ' (imported ' . $copy . ')';
			if (!$mapper->nameExists($uid, $candidate)) return $candidate;
		}
		throw new UserMigrationException('Could not create a unique imported name');
	}
}
