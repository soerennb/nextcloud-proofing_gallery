<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use DateTimeImmutable;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Utility\ITimeFactory;

final class EventWorkflowService {
	public function __construct(
		private EventSetupService $setups,
		private EventWaveService $waves,
		private PublicShareService $shares,
		private ITimeFactory $clock,
	) {
	}

	/** @return array{gallery: Gallery, wave: array<string, mixed>} */
	public function deliver(Gallery $gallery, int $setupRevision, string $requestKey): array {
		$plan = $this->setups->deliveryPlan($gallery, $setupRevision);
		$delivery = $plan['delivery']; $releaseMode = (string)$delivery['releaseMode'];
		$releaseAt = null;
		if ($releaseMode === 'schedule') {
			try { $releaseAt = (new DateTimeImmutable((string)$delivery['releaseAt']))->getTimestamp(); }
			catch (\Throwable) { throw new \InvalidArgumentException('Choose a valid future release time'); }
			if ($releaseAt <= $this->clock->getTime()) throw new \InvalidArgumentException('Choose a future release time');
		}
		if ($releaseMode !== 'draft' && $gallery->getShareToken() === null) {
			$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$gallery = $this->shares->publish($gallery, null, null, $settings->delivery->downloadScope->value);
		}
		$wave = $this->waves->create(
			$gallery, $plan['sharedRoots'], $plan['recipients'], [],
			$delivery['expiresAt'] === '' ? null : (string)$delivery['expiresAt'], $releaseAt,
			(bool)$delivery['sendInvitations'], $releaseMode === 'now', $requestKey,
		);
		return compact('gallery', 'wave');
	}

	/** @return array{gallery: Gallery, wave: array<string, mixed>} */
	public function release(Gallery $gallery, int $waveId): array {
		if ($gallery->getShareToken() === null) {
			$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$gallery = $this->shares->publish($gallery, null, null, $settings->delivery->downloadScope->value);
		}
		return ['gallery' => $gallery, 'wave' => $this->waves->release($gallery, $waveId)];
	}
}
