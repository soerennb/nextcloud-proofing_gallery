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
		$preset->setSettings(json_encode(GallerySettings::fromArray($settings), JSON_THROW_ON_ERROR));
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
			$preset->setSettings(json_encode(GallerySettings::fromArray($settings), JSON_THROW_ON_ERROR));
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
		if ($gallery->getSourceType() === 'collection') {
			$settings['allowGuestUploads'] = false;
		}
		return $this->galleries->update($ownerUid, $galleryId, null, $settings, $expectedRevision);
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
}
