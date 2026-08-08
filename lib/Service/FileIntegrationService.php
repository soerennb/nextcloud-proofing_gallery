<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

final class FileIntegrationService {
	public function __construct(
		private IRootFolder $root,
		private GalleryAccessService $access,
		private GalleryService $galleries,
		private IntegrationReadService $read,
	) {
	}

	/** @return array{items: list<array<string, mixed>>, canCreate: bool, folderName: string} */
	public function resolve(string $userUid, int $fileId): array {
		$folder = $this->folder($userUid, $fileId);
		$items = array_values(array_filter(
			$this->access->list($userUid, false, ''),
			static fn (Gallery $gallery): bool => $gallery->getSourceType() === 'folder' && $gallery->getFolderId() === $fileId,
		));
		return [
			'items' => array_map(fn (Gallery $gallery): array => $this->read->galleryById($userUid, (int)$gallery->getId()), $items),
			'canCreate' => $folder->isCreatable(),
			'folderName' => $folder->getName(),
		];
	}

	/** @return array<string, mixed> */
	public function create(string $userUid, int $fileId, ?string $title = null): array {
		$folder = $this->folder($userUid, $fileId);
		$resolved = $this->resolve($userUid, $fileId);
		if ($resolved['items'] !== []) throw new \InvalidArgumentException('This folder already has a gallery');
		$gallery = $this->galleries->create($userUid, trim((string)$title) !== '' ? (string)$title : $folder->getName(), $fileId);
		return $this->read->galleryById($userUid, (int)$gallery->getId());
	}

	private function folder(string $userUid, int $fileId): Folder {
		foreach ($this->root->getUserFolder($userUid)->getById($fileId) as $node) {
			if ($node instanceof Folder) return $node;
		}
		throw new \OCP\AppFramework\Db\DoesNotExistException('Folder not found');
	}
}
