<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

final class PublicGalleryDataService {
	public function __construct(private CollectionService $collections) {
	}

	/** @return array<string, mixed> */
	public function page(Gallery $gallery, Folder $root, int $limit = 60, int $offset = 0, string $path = ''): array {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$path = trim($path, '/');
		if ($gallery->getSourceType() === 'collection') {
			if ($path !== '') {
				throw new \OCP\Files\NotFoundException('Collections do not contain folders');
			}
			$nodes = $this->collections->availableItems($gallery);
			return $this->response($gallery, array_slice($nodes, $offset, $limit), count($nodes), $limit, $offset, '');
		}
		$currentFolder = $this->folderAt($root, $path);
		$nodes = array_values(array_filter(
			$currentFolder->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node))),
		));
		usort($nodes, static fn (Node $left, Node $right): int => strnatcasecmp($left->getName(), $right->getName()));

		$items = array_map(static fn (Node $node): array => [
			'id' => $node->getId(),
			'name' => $node->getName(),
			'mimeType' => $node->getMimeType(),
			'size' => (int)$node->getSize(),
			'modifiedAt' => $node->getMTime(),
			'etag' => $node->getEtag(),
			'folder' => $node instanceof Folder,
		], array_slice($nodes, $offset, $limit));

		return $this->response($gallery, $items, count($nodes), $limit, $offset, $path);
	}

	/** @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private function response(Gallery $gallery, array $items, int $total, int $limit, int $offset, string $path): array {
		return [
			'gallery' => [
				'id' => $gallery->getId(),
				'title' => $gallery->getTitle(),
				'settings' => GallerySettings::fromArray(json_decode(
					$gallery->getSettings(),
					true,
					flags: JSON_THROW_ON_ERROR,
				)),
			],
			'items' => $items,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'path' => $path,
		];
	}

	private function folderAt(Folder $root, string $path): Folder {
		if ($path === '') {
			return $root;
		}
		if (in_array('..', explode('/', $path), true)) {
			throw new \OCP\Files\NotFoundException('Invalid gallery path');
		}
		$node = $root->get($path);
		if (!$node instanceof Folder || !$root->isSubNode($node)) {
			throw new \OCP\Files\NotFoundException('Gallery folder not found');
		}
		return $node;
	}

	private function isSupported(File $file): bool {
		return str_starts_with($file->getMimeType(), 'image/')
			|| in_array($file->getMimeType(), ['video/mp4', 'video/webm'], true);
	}
}
