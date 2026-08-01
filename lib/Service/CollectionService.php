<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Exception\CollectionConflictException;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

final class CollectionService {
	public const MAX_ITEMS = 1000;

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private GalleryMapper $galleries,
		private FolderService $folders,
		private ITimeFactory $clock,
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
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_collections')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT),
			'revision' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
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
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('proofing_collections')
				->set('revision', $qb->createNamedParameter($revision + 1, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)));
			if ($qb->executeStatement() !== 1) {
				throw new CollectionConflictException('The collection changed in another session');
			}

			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_collection_items')
				->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT)))
				->executeStatement();
			foreach ($validated as $position => $item) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('proofing_collection_items')->values([
					'collection_id' => $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT),
					'source_gallery_id' => $qb->createNamedParameter($item['sourceGalleryId'], IQueryBuilder::PARAM_INT),
					'file_id' => $qb->createNamedParameter($item['fileId'], IQueryBuilder::PARAM_INT),
					'position' => $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}

		$collection->setUpdatedAt($now);
		$this->galleries->update($collection);
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
		$this->assertCollection($collection);
		$items = [];
		foreach ($this->rows($collection->getId()) as $row) {
			try {
				$source = $this->ownedFolderGallery($collection, (int)$row['source_gallery_id']);
				$file = $this->folders->resolveMedia($source->getOwnerUid(), $source->getFolderId(), (int)$row['file_id']);
				$items[] = [
					'id' => $file->getId(),
					'name' => $file->getName(),
					'mimeType' => $file->getMimeType(),
					'size' => (int)$file->getSize(),
					'modifiedAt' => $file->getMTime(),
					'etag' => $file->getEtag(),
					'folder' => false,
					'sourceGalleryId' => $source->getId(),
					'sourceGalleryTitle' => $source->getTitle(),
				];
			} catch (DoesNotExistException|FolderAccessException) {
				// Unavailable references remain visible to the owner, but never to guests.
			}
		}
		return $items;
	}

	public function resolveMedia(Gallery $collection, int $fileId): File {
		$this->assertCollection($collection);
		$qb = $this->db->getQueryBuilder();
		$qb->select('source_gallery_id')->from('proofing_collection_items')
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$sourceId = $qb->executeQuery()->fetchOne();
		if ($sourceId === false) {
			throw new FolderAccessException('Media file was not found in the collection');
		}
		$source = $this->ownedFolderGallery($collection, (int)$sourceId);
		return $this->folders->resolveMedia($source->getOwnerUid(), $source->getFolderId(), $fileId);
	}

	public function downloadPath(Gallery $collection, File $file): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('source_gallery_id')->from('proofing_collection_items')
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collection->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)));
		$sourceId = $qb->executeQuery()->fetchOne();
		if ($sourceId === false) {
			throw new FolderAccessException('Media file was not found in the collection');
		}
		$source = $this->ownedFolderGallery($collection, (int)$sourceId);
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
			'total' => count($rows),
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
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_collection_items')
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC');
		return QueryResult::rows($qb->executeQuery());
	}

	private function revision(int $galleryId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('revision')->from('proofing_collections')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		$revision = $qb->executeQuery()->fetchOne();
		if ($revision === false) {
			throw new InvalidArgumentException('Collection metadata is missing');
		}
		return (int)$revision;
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
