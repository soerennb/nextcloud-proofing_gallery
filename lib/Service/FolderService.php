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
	): MediaPage {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$search = mb_substr(trim($search), 0, 120);
		$root = $this->resolveFolder($userId, $folderId);
		$current = $this->folderAt($root, trim($path, '/'));
		$nodes = array_values(array_filter(
			$current->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node)))
				&& ($search === '' || mb_stripos($node->getName(), $search) !== false),
		));
		usort($nodes, static fn (Node $left, Node $right): int => strnatcasecmp($left->getName(), $right->getName()));

		$items = array_map(
			static fn (Node $node): MediaItem => new MediaItem(
				$node->getId(),
				$node->getName(),
				$node->getMimeType(),
				(int)$node->getSize(),
				$node->getMTime(),
				$node->getEtag(),
				$node instanceof Folder,
			),
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
