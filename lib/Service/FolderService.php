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

	public function listMedia(string $userId, int $folderId, int $limit = 60, int $offset = 0): MediaPage {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$nodes = array_values(array_filter(
			$this->resolveFolder($userId, $folderId)->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node))),
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

	/**
	 * @return array{
	 *   source: array{folderId: int, displayPath: ?string, state: 'readable'},
	 *   mediaSummary: array{total: int, coverFileId: ?int, coverMimeType: ?string}
	 * }
	 */
	public function describe(string $userId, int $folderId, bool $includePath): array {
		$folder = $this->resolveFolder($userId, $folderId);
		$total = 0;
		$coverFileId = null;
		$coverMimeType = null;

		foreach ($folder->getDirectoryListing() as $node) {
			if (str_starts_with($node->getName(), '.')
				|| !($node instanceof Folder || ($node instanceof File && $this->isSupported($node)))) {
				continue;
			}
			$total++;
			if ($coverFileId === null && $node instanceof File) {
				$coverFileId = $node->getId();
				$coverMimeType = $node->getMimeType();
			}
			if ($node instanceof File
				&& str_starts_with($node->getMimeType(), 'image/')
				&& ($coverMimeType === null || !str_starts_with($coverMimeType, 'image/'))) {
				$coverFileId = $node->getId();
				$coverMimeType = $node->getMimeType();
			}
		}

		$path = null;
		if ($includePath) {
			$prefix = '/' . $userId . '/files';
			$path = str_starts_with($folder->getPath(), $prefix)
				? substr($folder->getPath(), strlen($prefix)) ?: '/'
				: '/' . $folder->getName();
		}

		return [
			'source' => [
				'folderId' => $folderId,
				'displayPath' => $path,
				'state' => 'readable',
			],
			'mediaSummary' => [
				'total' => $total,
				'coverFileId' => $coverFileId,
				'coverMimeType' => $coverMimeType,
			],
		];
	}

	private function isSupported(File $file): bool {
		return str_starts_with($file->getMimeType(), 'image/')
			|| in_array($file->getMimeType(), self::SUPPORTED_VIDEO_MIMES, true);
	}
}
