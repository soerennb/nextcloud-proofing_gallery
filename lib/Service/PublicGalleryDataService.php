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
	public function page(
		Gallery $gallery,
		Folder $root,
		int $limit = 60,
		int $offset = 0,
		string $path = '',
		string $search = '',
		string $sortBy = '',
		string $sortDirection = '',
		string $groupBy = '',
	): array {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$path = trim($path, '/');
		$search = mb_substr(trim($search), 0, 120);
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$sortBy = $sortBy !== '' ? $sortBy : $settings->navigation['sortBy'];
		$sortDirection = $sortDirection !== '' ? $sortDirection : $settings->navigation['sortDirection'];
		$groupBy = $groupBy !== '' ? $groupBy : $settings->navigation['groupBy'];
		if (!in_array($sortBy, ['name', 'modified', 'size'], true)
			|| !in_array($sortDirection, ['asc', 'desc'], true)
			|| !in_array($groupBy, ['none', 'type'], true)) {
			throw new \InvalidArgumentException('Invalid gallery arrangement');
		}
		if ($gallery->getSourceType() === 'collection') {
			if ($path !== '') {
				throw new \OCP\Files\NotFoundException('Collections do not contain folders');
			}
			$nodes = array_values(array_filter(
				$this->collections->availableItems($gallery),
				static fn (array $item): bool => $search === '' || mb_stripos((string)$item['name'], $search) !== false,
			));
			return $this->response($gallery, array_slice($nodes, $offset, $limit), count($nodes), $limit, $offset, '', $search, 'collection', 'asc', 'none');
		}
		if (!$settings->navigation['folders'] && $path !== '') {
			throw new \OCP\Files\NotFoundException('Folder navigation is disabled');
		}
		$currentFolder = $this->folderAt($root, $path);
		$nodes = array_values(array_filter(
			$currentFolder->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node))),
		));
		$nodes = array_values(array_filter(
			$nodes,
			static fn (Node $node): bool => ($settings->navigation['folders'] || !($node instanceof Folder))
				&& ($search === '' || mb_stripos($node->getName(), $search) !== false),
		));
		usort($nodes, static function (Node $left, Node $right) use ($sortBy, $sortDirection, $groupBy): int {
			if ($groupBy === 'type') {
				$group = self::group($left) <=> self::group($right);
				if ($group !== 0) return $group;
			}
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

		$items = array_map(static fn (Node $node): array => [
			'id' => $node->getId(),
			'name' => $node->getName(),
			'mimeType' => $node->getMimeType(),
			'size' => (int)$node->getSize(),
			'modifiedAt' => $node->getMTime(),
			'etag' => $node->getEtag(),
			'folder' => $node instanceof Folder,
			'group' => self::group($node),
		], array_slice($nodes, $offset, $limit));

		return $this->response($gallery, $items, count($nodes), $limit, $offset, $path, $search, $sortBy, $sortDirection, $groupBy);
	}

	/** @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private function response(
		Gallery $gallery,
		array $items,
		int $total,
		int $limit,
		int $offset,
		string $path,
		string $search,
		string $sortBy,
		string $sortDirection,
		string $groupBy,
	): array {
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
			'view' => compact('search', 'sortBy', 'sortDirection', 'groupBy'),
		];
	}

	private static function group(Node $node): string {
		if ($node instanceof Folder) return 'folder';
		return str_starts_with($node->getMimeType(), 'video/') ? 'video' : 'image';
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
