<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

final class CollectionService {
	public const MAX_ITEMS = 1000;

	public function __construct(
		private CollectionRepository $repository,
		private IRootFolder $rootFolder,
		private GalleryMapper $galleries,
		private FolderService $folders,
		private ITimeFactory $clock,
		private GalleryMediaCountInvalidator $countInvalidator,
		private \OCA\ProofingGallery\Db\GalleryMediaCountRepository $counts,
	) {
	}

	public function createAnchor(string $ownerUid): Folder {
		$userFolder = $this->rootFolder->getUserFolder($ownerUid);
		$appFolder = $this->folder($userFolder, '.proofing-gallery');
		$collectionFolder = $this->folder($appFolder, 'collections');
		return $collectionFolder->newFolder(bin2hex(random_bytes(16)));
	}

	public function initialize(Gallery $gallery): void {
		$this->assertCollection($gallery);
		$this->repository->initialize($gallery->getId(), $this->clock->getTime());
	}

	public function deleteAnchor(Folder $anchor): void {
		$anchor->delete();
	}

	/**
	 * @param list<array{sourceGalleryId: int, fileId: int}> $items
	 * @return array<string, mixed>
	 */
	public function replace(Gallery $collection, int $revision, array $items): array {
		$this->assertCollection($collection);
		if (count($items) > self::MAX_ITEMS) {
			throw new InvalidArgumentException('A collection can contain at most 1000 files');
		}

		$validated = [];
		$seen = [];
		foreach ($items as $item) {
			$sourceGalleryId = (int)($item['sourceGalleryId'] ?? 0);
			$fileId = (int)($item['fileId'] ?? 0);
			if ($sourceGalleryId <= 0 || $fileId <= 0 || isset($seen[$fileId])) {
				throw new InvalidArgumentException('Collection files must be unique and valid');
			}
			$source = $this->ownedFolderGallery($collection, $sourceGalleryId);
			$this->folders->resolveMedia($source->getOwnerUid(), $source->getFolderId(), $fileId);
			$validated[] = ['sourceGalleryId' => $sourceGalleryId, 'fileId' => $fileId];
			$seen[$fileId] = true;
		}

		$now = $this->clock->getTime();
		$this->repository->replace($collection->getId(), $revision, $validated, $now);

		$collection->setUpdatedAt($now);
		$this->galleries->update($collection);
		$this->countInvalidator->invalidate((int)$collection->getId());
		return $this->document($collection);
	}

	/** @return array<string, mixed> */
	public function document(Gallery $collection): array {
		$this->assertCollection($collection);
		$items = [];
		$unavailable = 0;
		foreach ($this->rows($collection->getId()) as $row) {
			try {
				$source = $this->ownedFolderGallery($collection, (int)$row['source_gallery_id']);
				$file = $this->folders->resolveMedia($source->getOwnerUid(), $source->getFolderId(), (int)$row['file_id']);
				$items[] = [
					'sourceGalleryId' => $source->getId(),
					'sourceGalleryTitle' => $source->getTitle(),
					'fileId' => $file->getId(),
					'name' => $file->getName(),
					'mimeType' => $file->getMimeType(),
					'size' => (int)$file->getSize(),
					'modifiedAt' => $file->getMTime(),
					'etag' => $file->getEtag(),
					'state' => 'available',
				];
			} catch (DoesNotExistException|FolderAccessException) {
				$unavailable++;
				$items[] = [
					'sourceGalleryId' => (int)$row['source_gallery_id'],
					'sourceGalleryTitle' => null,
					'fileId' => (int)$row['file_id'],
					'name' => null,
					'mimeType' => null,
					'size' => null,
					'modifiedAt' => null,
					'etag' => null,
					'state' => 'unavailable',
				];
			}
		}
		return [
			'revision' => $this->revision($collection->getId()),
			'items' => $items,
			'unavailableCount' => $unavailable,
		];
	}

	/** @return list<array<string, mixed>> */
	public function availableItems(Gallery $collection): array {
		return iterator_to_array($this->availableItemStream($collection), false);
	}

	/** @return \Generator<int, array<string, mixed>> */
	public function availableItemStream(Gallery $collection): \Generator {
		foreach ($this->availableFileStream($collection) as ['item' => $item]) {
			yield $item;
		}
	}

	/**
	 * Available items together with their resolved files, so public pages can read metadata
	 * without resolving every reference a second time.
	 *
	 * @return \Generator<int, array{item: array<string, mixed>, file: File}>
	 */
	public function availableFileStream(Gallery $collection): \Generator {
		$this->assertCollection($collection);
		// Each source gallery and its folder are resolved once per listing, not once per item.
		$sources = [];
		$folders = [];
		foreach ($this->collectionRows($collection) as $row) {
			try {
				$sourceId = (int)$row['source_gallery_id'];
				$source = $sources[$sourceId] ??= $this->ownedFolderGallery($collection, $sourceId);
				$folder = $folders[$sourceId] ??= $this->folders->resolveFolder($source->getOwnerUid(), $source->getFolderId());
				$file = $this->folders->resolveMediaIn($folder, (int)$row['file_id']);
				yield ['item' => [
					'id' => $file->getId(),
					'name' => $file->getName(),
					'mimeType' => $file->getMimeType(),
					'size' => (int)$file->getSize(),
					'modifiedAt' => $file->getMTime(),
					'etag' => $file->getEtag(),
					'folder' => false,
					'sourceGalleryId' => $source->getId(),
					'sourceGalleryTitle' => $source->getTitle(),
				], 'file' => $file];
			} catch (DoesNotExistException|FolderAccessException) {
				// Unavailable references remain visible to the owner, but never to guests.
			}
		}
	}

	/**
	 * Fingerprint of everything a collection listing depends on: membership revision, each source
	 * gallery revision and the etag of each source folder. Nextcloud propagates file changes and
	 * deletions to the folder etag, so a changed fingerprint means the listing must be rebuilt.
	 * Returns null when a source cannot be fingerprinted; callers must then skip the cache.
	 */
	public function listingFingerprint(Gallery $collection): ?string {
		$this->assertCollection($collection);
		$revision = $this->repository->revision((int)$collection->getId());
		if ($revision === null) return null;
		$sourceIds = [];
		foreach ($this->collectionRows($collection) as $row) $sourceIds[(int)$row['source_gallery_id']] = true;
		$sources = [];
		try {
			foreach (array_keys($sourceIds) as $sourceId) {
				$source = $this->ownedFolderGallery($collection, $sourceId);
				$folder = $this->folders->resolveFolder($source->getOwnerUid(), $source->getFolderId());
				$sources[] = [$sourceId, $source->getRevision(), $folder->getId(), $folder->getEtag()];
			}
		} catch (\Throwable) {
			return null;
		}
		return hash('sha256', json_encode([(int)$collection->getId(), $revision, $sources], JSON_THROW_ON_ERROR));
	}

	/** @return \Generator<int, array<string, mixed>> */
	private function collectionRows(Gallery $collection): \Generator {
		$after = 0;
		do {
			$rows = $this->repository->page((int)$collection->getId(), $after, 1000);
			foreach ($rows as $row) { $after = (int)$row['id']; yield $row; }
		} while (count($rows) === 1000);
	}

	public function resolveMedia(Gallery $collection, int $fileId): File {
		$this->assertCollection($collection);
		$sourceId = $this->repository->sourceGalleryId($collection->getId(), $fileId);
		if ($sourceId === null) {
			throw new FolderAccessException('Media file was not found in the collection');
		}
		$source = $this->ownedFolderGallery($collection, $sourceId);
		return $this->folders->resolveMedia($source->getOwnerUid(), $source->getFolderId(), $fileId);
	}

	public function downloadPath(Gallery $collection, File $file): string {
		$sourceId = $this->repository->sourceGalleryId($collection->getId(), $file->getId());
		if ($sourceId === null) {
			throw new FolderAccessException('Media file was not found in the collection');
		}
		$source = $this->ownedFolderGallery($collection, $sourceId);
		$folder = trim(preg_replace('/[^a-z0-9._-]+/i', '-', $source->getTitle()) ?? '', '-');
		return ($folder !== '' ? $folder : 'source') . '/' . $file->getName();
	}

	/** @return array{total: int, coverFileId: ?int, coverMimeType: ?string} */
	public function summary(Gallery $collection): array {
		$rows = $this->rows($collection->getId());
		$cover = null;
		foreach ($rows as $row) {
			try {
				$cover = $this->resolveMedia($collection, (int)$row['file_id']);
				if (str_starts_with($cover->getMimeType(), 'image/')) {
					break;
				}
			} catch (DoesNotExistException|FolderAccessException) {
				// Keep looking for the first currently available cover.
			}
		}
		return [
			...GalleryMediaCountService::present($this->counts->find((int)$collection->getId())),
			'coverFileId' => $cover?->getId(),
			'coverMimeType' => $cover?->getMimeType(),
		];
	}

	/** @return array{type: 'collection', state: 'readable'|'degraded', itemCount: int, unavailableCount: int} */
	public function sourceStatus(Gallery $collection): array {
		$document = $this->document($collection);
		return [
			'type' => 'collection',
			'state' => $document['unavailableCount'] > 0 ? 'degraded' : 'readable',
			'itemCount' => count($document['items']),
			'unavailableCount' => $document['unavailableCount'],
		];
	}

	/** @return list<array<string, mixed>> */
	private function rows(int $collectionId): array {
		return $this->repository->items($collectionId);
	}

	private function revision(int $galleryId): int {
		$revision = $this->repository->revision($galleryId);
		if ($revision === null) {
			throw new InvalidArgumentException('Collection metadata is missing');
		}
		return $revision;
	}

	private function ownedFolderGallery(Gallery $collection, int $sourceGalleryId): Gallery {
		$source = $this->galleries->find($sourceGalleryId);
		if ($source->getOwnerUid() !== $collection->getOwnerUid()
			|| $source->getSourceType() !== 'folder'
			|| $source->getId() === $collection->getId()) {
			throw new InvalidArgumentException('Collections may only use the owner\'s folder galleries');
		}
		return $source;
	}

	private function assertCollection(Gallery $gallery): void {
		if ($gallery->getSourceType() !== 'collection') {
			throw new InvalidArgumentException('Gallery is not a collection');
		}
	}

	private function folder(Folder $parent, string $name): Folder {
		try {
			$node = $parent->get($name);
			if (!$node instanceof Folder) {
				throw new \RuntimeException('Reserved collection path is not a folder');
			}
			return $node;
		} catch (\OCP\Files\NotFoundException) {
			return $parent->newFolder($name);
		}
	}
}
