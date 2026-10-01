<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\IndexMediaMetadataJob;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\KioskRepository;
use OCA\ProofingGallery\Exception\KioskConflictException;
use OCA\ProofingGallery\Exception\KioskRateLimitException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;

final class KioskPhotoService {
	public function __construct(
		private KioskRepository $kiosks,
		private KioskLinkService $links,
		private FolderService $folders,
		private UploadLockService $locks,
		private MediaSummaryService $summaries,
		private ITimeFactory $clock,
		private IJobList $jobs,
	) {
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function upload(Gallery $gallery, string $photoId, string $path, string $checksum): array {
		$photoId = strtolower($photoId);
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $photoId) !== 1) throw new \InvalidArgumentException('photoId must be a UUID');
		return $this->locks->wait('proofing-gallery/kiosk-upload/' . $gallery->getId(), 'Fotobox gallery upload', fn (): array => $this->store($gallery, $photoId, $path, $checksum));
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	private function store(Gallery $gallery, string $photoId, string $path, string $checksum): array {
		$this->links->context($gallery);
		$galleryId = (int)$gallery->getId();
		$receipt = $this->kiosks->photo($galleryId, $photoId);
		if ($receipt !== null && !hash_equals((string)$receipt['checksum'], $checksum)) throw new KioskConflictException('photoId was already used for different image content');
		if ($receipt !== null && $receipt['file_id'] !== null) {
			$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), (int)$receipt['file_id']);
			if (!hash_equals($checksum, $this->checksum($file))) throw new KioskConflictException('The stored kiosk photo changed; it will not be overwritten');
			return ['data' => $this->response($gallery, $photoId, (int)$receipt['file_id']), 'replayed' => true];
		}
		if ($this->kiosks->recentUploads($galleryId, $this->clock->getTime() - 60) >= 60) throw new KioskRateLimitException('The gallery accepts at most 60 new photos per minute; retry later');
		if ($receipt === null) $this->kiosks->reservePhoto($galleryId, $photoId, $checksum, $this->clock->getTime());
		$file = $this->recoverOrStore($gallery, $photoId, $path, $checksum);
		// File commit and DB commit are separate boundaries. A pending receipt
		// recovers the deterministic file and verifies its checksum after a crash.
		$data = $this->response($gallery, $photoId, (int)$file->getId());
		$this->kiosks->transaction(function () use ($galleryId, $photoId, $file): void {
			$this->kiosks->storedPhoto($galleryId, $photoId, (int)$file->getId(), $this->clock->getTime());
			$this->summaries->invalidate($galleryId);
			$this->jobs->add(IndexMediaMetadataJob::class, ['galleryId' => $galleryId, 'fileId' => (int)$file->getId()]);
			$this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => $galleryId]);
		});
		return ['data' => $data, 'replayed' => false];
	}

	private function recoverOrStore(Gallery $gallery, string $photoId, string $path, string $checksum): File {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$name = $photoId . '.jpg';
		if ($root->nodeExists($name)) {
			$file = $root->get($name);
			if (!$file instanceof File || !hash_equals($checksum, $this->checksum($file))) throw new KioskConflictException('The stored kiosk photo changed; it will not be overwritten');
			return $file;
		}
		$stream = fopen($path, 'rb');
		if ($stream === false) throw new \RuntimeException('The received photo could not be opened');
		try {
			$staged = $this->folders->stageMedia($gallery->getOwnerUid(), $gallery->getFolderId(), '', $name, $stream, 'image/jpeg', str_replace('-', '', $photoId));
		} finally { if (is_resource($stream)) fclose($stream); }
		$this->folders->commitStagedMedia($gallery->getOwnerUid(), $gallery->getFolderId(), '', $name, (int)$staged->getId(), $staged->getName());
		$file = $root->get($name);
		if (!$file instanceof File) throw new \RuntimeException('The stored photo could not be read');
		return $file;
	}

	private function checksum(File $file): string {
		$stream = $file->fopen('rb');
		if (!is_resource($stream)) throw new \RuntimeException('The stored photo could not be verified');
		try {
			$hash = hash_init('sha256');
			if (hash_update_stream($hash, $stream) !== $file->getSize()) throw new \RuntimeException('The stored photo could not be verified');
			return hash_final($hash);
		} finally { fclose($stream); }
	}

	/** @return array<string, mixed> */
	private function response(Gallery $gallery, string $photoId, int $fileId): array {
		return ['photoId' => $photoId, 'fileId' => $fileId, 'status' => 'stored', 'photoUrl' => $this->links->photoUrl($gallery, $fileId)];
	}
}
