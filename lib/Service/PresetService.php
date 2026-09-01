<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Preset;
use OCA\ProofingGallery\Db\PresetMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;

final class PresetService {
	public function __construct(
		private PresetMapper $presets,
		private GalleryService $galleries,
		private ITimeFactory $clock,
		private DesignAssetService $designAssets,
	) {
	}

	/** @return list<Preset> */
	public function list(string $ownerUid): array {
		return $this->presets->findAllOwned($ownerUid);
	}

	/** @param array<string, mixed> $settings */
	public function create(string $ownerUid, string $name, array $settings): Preset {
		$name = $this->validateName($ownerUid, $name);
		$now = $this->clock->getTime();
		$preset = new Preset();
		$preset->setOwnerUid($ownerUid);
		$preset->setName($name);
		$preset->setSettings(json_encode($this->portable($ownerUid, $settings), JSON_THROW_ON_ERROR));
		$preset->setCreatedAt($now);
		$preset->setUpdatedAt($now);
		return $this->presets->insert($preset);
	}

	/** @param array<string, mixed>|null $settings */
	public function update(string $ownerUid, int $id, ?string $name, ?array $settings): Preset {
		$preset = $this->presets->findOwned($id, $ownerUid);
		if ($name !== null) {
			$preset->setName($this->validateName($ownerUid, $name, $id));
		}
		if ($settings !== null) {
			$preset->setSettings(json_encode($this->portable($ownerUid, $settings), JSON_THROW_ON_ERROR));
		}
		$preset->setUpdatedAt($this->clock->getTime());
		return $this->presets->update($preset);
	}

	public function delete(string $ownerUid, int $id): void {
		$this->presets->delete($this->presets->findOwned($id, $ownerUid));
	}

	public function apply(string $ownerUid, int $id, int $galleryId, ?int $expectedRevision = null): Gallery {
		$preset = $this->presets->findOwned($id, $ownerUid);
		$settings = json_decode($preset->getSettings(), true, flags: JSON_THROW_ON_ERROR);
		$gallery = $this->galleries->get($ownerUid, $galleryId);
		$current = GallerySettings::fromArray(
			json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR),
		)->canonical();
		$settings = $this->preserveGalleryReferences($settings, $current);
		if ($gallery->getSourceType() === 'collection') {
			$settings['delivery']['guestUploads'] = false;
			unset($settings['allowGuestUploads']);
		}
		return $this->galleries->update($ownerUid, $galleryId, null, $settings, $expectedRevision);
	}

	/** @param array<string, mixed> $preset
	 * @param array<string, mixed> $current
	 * @return array<string, mixed>
	 */
	private function preserveGalleryReferences(array $preset, array $current): array {
		foreach (['heroFileId', 'logoFileId', 'instanceLogoAssetId', 'instanceStudioName'] as $field) {
			$preset['presentation'][$field] = $current['presentation'][$field];
		}
		$currentSections = array_column($current['presentation']['story']['sections'], null, 'id');
		foreach ($preset['presentation']['story']['sections'] as &$section) {
			$section['mediaIds'] = $currentSections[$section['id']]['mediaIds'] ?? [];
		}
		unset($section);
		return $preset;
	}

	private function validateName(string $ownerUid, string $name, ?int $exceptId = null): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) {
			throw new InvalidArgumentException('Preset name must contain 1 to 120 characters');
		}
		if ($this->presets->nameExists($ownerUid, $name, $exceptId)) {
			throw new InvalidArgumentException('A preset with this name already exists');
		}
		return $name;
	}

	/** @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function portable(string $ownerUid, array $input): array {
		$settings = GallerySettings::fromArray($input);
		foreach ([[$settings->presentation->logoAssetId, 'logo'], [$settings->presentation->watermarkImageAssetId, 'watermark']] as [$assetId, $kind]) {
			if ($assetId === null) continue;
			try {
				$this->designAssets->owned($ownerUid, $assetId, $kind);
			} catch (\OCP\Files\NotFoundException|\OCP\AppFramework\Db\DoesNotExistException $exception) {
				throw new InvalidArgumentException('Preset assets must belong to their owner and match their intended use', previous: $exception);
			}
		}
		$portable = $settings->canonical();
		$portable['presentation']['heroFileId'] = null;
		$portable['presentation']['logoFileId'] = null;
		$portable['presentation']['instanceLogoAssetId'] = null;
		$portable['presentation']['instanceStudioName'] = '';
		if ($portable['presentation']['logoMode'] === 'gallery') $portable['presentation']['logoMode'] = 'inherit';
		foreach ($portable['presentation']['story']['sections'] as &$section) $section['mediaIds'] = [];
		unset($section);
		return $portable;
	}
}
