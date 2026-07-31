<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\MediaItem;
use OCA\ProofingGallery\Dto\MediaPage;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;

final class FolderService {
	private const SUPPORTED_VIDEO_MIMES = ['video/mp4', 'video/webm'];

	public function __construct(private IRootFolder $rootFolder) {
	}

	public function resolveFolder(string $userId, int $folderId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($folderId);

		foreach ($nodes as $node) {
			if ($node instanceof Folder && $node->isReadable()) {
				return $node;
			}
		}

		throw new FolderAccessException('Folder was not found or is not readable');
	}

	public function resolveMedia(string $userId, int $folderId, int $fileId): File {
		$folder = $this->resolveFolder($userId, $folderId);
		foreach ($folder->getById($fileId) as $node) {
			if ($node instanceof File && $folder->isSubNode($node) && $node->isReadable() && $this->isSupported($node)) {
				return $node;
			}
		}

		throw new FolderAccessException('Media file was not found in the gallery');
	}

	public function uploadMedia(string $userId, int $folderId, string $path, string $filename, string $temporaryPath): MediaItem {
		$root = $this->resolveFolder($userId, $folderId);
		$target = $this->folderAt($root, trim($path, '/'));
		$filename = $this->safeName($filename);
		if (!$target->isUpdateable() || $target->nodeExists($filename)) {
			throw new FolderAccessException('The destination is not writable or the filename already exists');
		}
		$stream = fopen($temporaryPath, 'rb');
		if ($stream === false) {
			throw new FolderAccessException('The uploaded file could not be read');
		}
		try {
			$file = $target->newFile($filename, $stream);
		} finally {
			fclose($stream);
		}
		if (!$this->isSupported($file)) {
			$file->delete();
			throw new \InvalidArgumentException('Only images, MP4 and WebM files are accepted');
		}
		return $this->mediaItem($file);
	}

	public function createFolder(string $userId, int $folderId, string $path, string $name): MediaItem {
		$root = $this->resolveFolder($userId, $folderId);
		$target = $this->folderAt($root, trim($path, '/'));
		$name = $this->safeName($name);
		if (!$target->isUpdateable() || $target->nodeExists($name)) {
			throw new FolderAccessException('The destination is not writable or the name already exists');
		}
		return $this->mediaItem($target->newFolder($name));
	}

	public function renameNode(string $userId, int $folderId, int $nodeId, string $name): MediaItem {
		$root = $this->resolveFolder($userId, $folderId);
		$node = $this->nodeInGallery($root, $nodeId);
		$name = $this->safeName($name);
		$parent = $node->getParent();
		if (!$node->isUpdateable() || $parent->nodeExists($name)) {
			throw new FolderAccessException('The item cannot be renamed or the name already exists');
		}
		$node->move($parent->getPath() . '/' . $name);
		return $this->mediaItem($node);
	}

	public function deleteNode(string $userId, int $folderId, int $nodeId): void {
		$root = $this->resolveFolder($userId, $folderId);
		$node = $this->nodeInGallery($root, $nodeId);
		if (!$node->isDeletable()) {
			throw new FolderAccessException('The item cannot be deleted');
		}
		$node->delete();
	}

	public function resolveOwnerImage(string $userId, int $fileId): File {
		foreach ($this->rootFolder->getUserFolder($userId)->getById($fileId) as $node) {
			if ($node instanceof File && $node->isReadable() && str_starts_with($node->getMimeType(), 'image/')) {
				return $node;
			}
		}

		throw new FolderAccessException('Image file was not found or is not readable');
	}

	public function listMedia(
		string $userId,
		int $folderId,
		int $limit = 60,
		int $offset = 0,
		string $path = '',
		string $search = '',
		string $sortBy = 'name',
		string $sortDirection = 'asc',
	): MediaPage {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$search = mb_substr(trim($search), 0, 120);
		if (!in_array($sortBy, ['name', 'modified', 'size'], true)
			|| !in_array($sortDirection, ['asc', 'desc'], true)) {
			throw new \InvalidArgumentException('Invalid media sort');
		}
		$root = $this->resolveFolder($userId, $folderId);
		$current = $this->folderAt($root, trim($path, '/'));
		$nodes = array_values(array_filter(
			$current->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node)))
				&& ($search === '' || mb_stripos($node->getName(), $search) !== false),
		));
		usort($nodes, static function (Node $left, Node $right) use ($sortBy, $sortDirection): int {
			if ($sortBy !== 'name') {
				$folderOrder = ($left instanceof Folder ? 0 : 1) <=> ($right instanceof Folder ? 0 : 1);
				if ($folderOrder !== 0) return $folderOrder;
			}
			$result = match ($sortBy) {
				'modified' => $left->getMTime() <=> $right->getMTime(),
				'size' => $left->getSize() <=> $right->getSize(),
				default => strnatcasecmp($left->getName(), $right->getName()),
			};
			if ($result === 0) $result = strnatcasecmp($left->getName(), $right->getName());
			return $sortDirection === 'desc' ? -$result : $result;
		});

		$items = array_map(
			fn (Node $node): MediaItem => $this->mediaItem($node),
			array_slice($nodes, $offset, $limit),
		);

		return new MediaPage($items, count($nodes), $limit, $offset);
	}

	private function folderAt(Folder $root, string $path): Folder {
		if ($path === '') {
			return $root;
		}
		if (in_array('..', explode('/', $path), true)) {
			throw new FolderAccessException('Invalid gallery path');
		}
		try {
			$node = $root->get($path);
		} catch (\OCP\Files\NotFoundException) {
			throw new FolderAccessException('Gallery folder was not found');
		}
		if (!$node instanceof Folder || !$root->isSubNode($node) || !$node->isReadable()) {
			throw new FolderAccessException('Gallery folder was not found');
		}
		return $node;
	}

	private function nodeInGallery(Folder $root, int $nodeId): Node {
		foreach ($root->getById($nodeId) as $node) {
			if (($node instanceof File || $node instanceof Folder) && $root->isSubNode($node)) {
				return $node;
			}
		}
		throw new FolderAccessException('Gallery item was not found');
	}

	private function safeName(string $name): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 255 || $name !== basename($name) || str_contains($name, "\0")) {
			throw new \InvalidArgumentException('Invalid filename');
		}
		return $name;
	}

	private function mediaItem(Node $node): MediaItem {
		return new MediaItem(
			$node->getId(),
			$node->getName(),
			$node->getMimeType(),
			(int)$node->getSize(),
			$node->getMTime(),
			$node->getEtag(),
			$node instanceof Folder,
		);
	}

	/** @return array{folderId: int, displayPath: ?string, state: 'readable'} */
	public function describeSource(string $userId, int $folderId, Folder $folder, bool $includePath): array {
		$path = null;
		if ($includePath) {
			$prefix = '/' . $userId . '/files';
			$path = str_starts_with($folder->getPath(), $prefix)
				? substr($folder->getPath(), strlen($prefix)) ?: '/'
				: '/' . $folder->getName();
		}

		return [
			'folderId' => $folderId,
			'displayPath' => $path,
			'state' => 'readable',
		];
	}

	private function isSupported(File $file): bool {
		return str_starts_with($file->getMimeType(), 'image/')
			|| in_array($file->getMimeType(), self::SUPPORTED_VIDEO_MIMES, true);
	}
}
