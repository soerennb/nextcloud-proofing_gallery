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

	public function __construct(
		private IRootFolder $rootFolder,
		private MediaMetadataService $metadata,
	) {
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

	public function createProjectFolder(string $userId, int $parentFolderId, string $name): Folder {
		$parent = $this->resolveFolder($userId, $parentFolderId);
		$name = $this->safeName($name);
		if (!$parent->isUpdateable() || $parent->nodeExists($name)) {
			throw new FolderAccessException('The project folder cannot be created or already exists');
		}
		return $parent->newFolder($name);
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

	public function uploadMedia(string $userId, int $folderId, string $path, string $filename, string $temporaryPath, string $conflict = 'fail'): ?MediaItem {
		$root = $this->resolveFolder($userId, $folderId);
		$target = $this->folderAt($root, trim($path, '/'));
		$filename = $this->safeName($filename);
		if (!$target->isUpdateable()) throw new FolderAccessException('The destination is not writable');
		if ($target->nodeExists($filename)) {
			if ($conflict === 'skip') return null;
			if ($conflict === 'overwrite') $target->get($filename)->delete();
			elseif ($conflict === 'rename') $filename = $this->conflictFreeName($target, $filename);
			else throw new FolderAccessException('The filename already exists');
		}
		$stream = fopen($temporaryPath, 'rb');
		if ($stream === false) {
			throw new FolderAccessException('The uploaded file could not be read');
		}
		try {
			$file = $target->newFile($filename, $stream);
		} finally {
			if (is_resource($stream)) fclose($stream);
		}
		if (!$this->isSupported($file)) {
			$file->delete();
			throw new \InvalidArgumentException('Only images, MP4 and WebM files are accepted');
		}
		return $this->mediaItem($file);
	}

	private function conflictFreeName(Folder $folder, string $filename): string {
		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		$stem = pathinfo($filename, PATHINFO_FILENAME);
		for ($copy = 2; $copy < 10000; $copy++) {
			$candidate = $stem . ' (' . $copy . ')' . ($extension === '' ? '' : '.' . $extension);
			if (!$folder->nodeExists($candidate)) return $candidate;
		}
		throw new FolderAccessException('A conflict-free filename could not be created');
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
		$sidecar = $this->sidecarFor($node);
		$newSidecarName = $node instanceof File ? pathinfo($name, PATHINFO_FILENAME) . '.xmp' : null;
		if (!$node->isUpdateable() || $parent->nodeExists($name)
			|| ($sidecar !== null && (!$sidecar->isUpdateable() || ($newSidecarName !== $sidecar->getName() && $parent->nodeExists((string)$newSidecarName))))) {
			throw new FolderAccessException('The item cannot be renamed or the name already exists');
		}
		$node->move($parent->getPath() . '/' . $name);
		if ($sidecar !== null && $newSidecarName !== $sidecar->getName()) $sidecar->move($parent->getPath() . '/' . $newSidecarName);
		return $this->mediaItem($node);
	}

	public function deleteNode(string $userId, int $folderId, int $nodeId): void {
		$root = $this->resolveFolder($userId, $folderId);
		$node = $this->nodeInGallery($root, $nodeId);
		$sidecar = $this->sidecarFor($node);
		if (!$node->isDeletable() || ($sidecar !== null && !$sidecar->isDeletable())) {
			throw new FolderAccessException('The item cannot be deleted');
		}
		$node->delete();
		$sidecar?->delete();
	}

	/** @param list<int> $nodeIds */
	public function deleteNodes(string $userId, int $folderId, array $nodeIds): int {
		$root = $this->resolveFolder($userId, $folderId);
		$nodes = $this->bulkNodes($root, $nodeIds);
		foreach ($nodes as $node) {
			$sidecar = $this->sidecarFor($node);
			if (!$node->isDeletable() || ($sidecar !== null && !$sidecar->isDeletable())) {
				throw new FolderAccessException('At least one selected item cannot be deleted');
			}
		}
		foreach ($nodes as $node) {
			$sidecar = $this->sidecarFor($node);
			$node->delete();
			$sidecar?->delete();
		}
		return count($nodes);
	}

	/** @param list<int> $nodeIds */
	public function moveNodes(string $userId, int $folderId, array $nodeIds, string $destinationPath): int {
		$root = $this->resolveFolder($userId, $folderId);
		$destination = $this->folderAt($root, trim($destinationPath, '/'));
		$nodes = $this->bulkNodes($root, $nodeIds);
		if (!$destination->isUpdateable()) {
			throw new FolderAccessException('The destination is not writable');
		}
		foreach ($nodes as $node) {
			$sidecar = $this->sidecarFor($node);
			if (!$node->isUpdateable() || $destination->nodeExists($node->getName())
				|| ($sidecar !== null && (!$sidecar->isUpdateable() || $destination->nodeExists($sidecar->getName())))) {
				throw new FolderAccessException('At least one selected item cannot be moved or already exists at the destination');
			}
			if ($node instanceof Folder && ($node->getPath() === $destination->getPath() || $node->isSubNode($destination))) {
				throw new FolderAccessException('A folder cannot be moved into itself');
			}
		}
		foreach ($nodes as $node) {
			$sidecar = $this->sidecarFor($node);
			$node->move($destination->getPath() . '/' . $node->getName());
			if ($sidecar !== null) $sidecar->move($destination->getPath() . '/' . $sidecar->getName());
		}
		return count($nodes);
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
		string $capturedFrom = '',
		string $capturedTo = '',
		string $camera = '',
		string $lens = '',
		string $keyword = '',
		int $ratingMin = 0,
	): MediaPage {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$search = mb_substr(trim($search), 0, 120);
		if (!in_array($sortBy, ['name', 'modified', 'size', 'capturedAt'], true)
			|| !in_array($sortDirection, ['asc', 'desc'], true)) {
			throw new \InvalidArgumentException('Invalid media sort');
		}
		if ($ratingMin < 0 || $ratingMin > 5) throw new \InvalidArgumentException('Invalid minimum rating');
		$capturedFromTime = $this->filterTimestamp($capturedFrom);
		$capturedToTime = $this->filterTimestamp($capturedTo, true);
		$root = $this->resolveFolder($userId, $folderId);
		$current = $this->folderAt($root, trim($path, '/'));
		$nodes = array_values(array_filter(
			$current->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node)))
				&& ($search === '' || mb_stripos($node->getName(), $search) !== false),
		));
		$metadataById = [];
		if ($capturedFrom !== '' || $capturedTo !== '' || $camera !== '' || $lens !== '' || $keyword !== '' || $ratingMin > 0 || $sortBy === 'capturedAt') {
			$nodes = array_values(array_filter($nodes, function (Node $node) use (&$metadataById, $capturedFromTime, $capturedToTime, $camera, $lens, $keyword, $ratingMin): bool {
				if ($node instanceof Folder) return true;
				if (!$node instanceof File) return false;
				$summary = $this->metadata->summary($node);
				$metadataById[$node->getId()] = $summary;
				return $this->matchesMetadata($summary, $capturedFromTime, $capturedToTime, $camera, $lens, $keyword, $ratingMin);
			}));
		}
		usort($nodes, static function (Node $left, Node $right) use ($sortBy, $sortDirection, $metadataById): int {
			if ($sortBy !== 'name') {
				$folderOrder = ($left instanceof Folder ? 0 : 1) <=> ($right instanceof Folder ? 0 : 1);
				if ($folderOrder !== 0) return $folderOrder;
			}
			$result = match ($sortBy) {
				'modified' => $left->getMTime() <=> $right->getMTime(),
				'size' => $left->getSize() <=> $right->getSize(),
				'capturedAt' => (int)($metadataById[$left->getId()]['capturedAt'] ?? 0) <=> (int)($metadataById[$right->getId()]['capturedAt'] ?? 0),
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

	/** @param list<int> $nodeIds
	 * @return list<Node>
	 */
	private function bulkNodes(Folder $root, array $nodeIds): array {
		$nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
		if ($nodeIds === [] || count($nodeIds) > 200 || in_array(0, $nodeIds, true)) {
			throw new \InvalidArgumentException('Select between 1 and 200 gallery items');
		}
		return array_map(fn (int $nodeId): Node => $this->nodeInGallery($root, $nodeId), $nodeIds);
	}

	private function safeName(string $name): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 255 || $name !== basename($name) || str_contains($name, "\0")) {
			throw new \InvalidArgumentException('Invalid filename');
		}
		return $name;
	}

	private function sidecarFor(Node $node): ?File {
		if (!$node instanceof File || !str_starts_with($node->getMimeType(), 'image/')) return null;
		$parent = $node->getParent();
		if (!$parent instanceof Folder) return null;
		$name = pathinfo($node->getName(), PATHINFO_FILENAME) . '.xmp';
		if (!$parent->nodeExists($name)) return null;
		$sidecar = $parent->get($name);
		return $sidecar instanceof File ? $sidecar : null;
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
			$node instanceof File ? $this->metadata->summary($node) : ['state' => 'unavailable'],
		);
	}

	private function filterTimestamp(string $value, bool $endOfDay = false): ?int {
		if ($value === '') return null;
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Invalid capture date');
		return $endOfDay ? $date->setTime(23, 59, 59)->getTimestamp() : $date->getTimestamp();
	}

	/** @param array<string, mixed> $summary */
	private function matchesMetadata(array $summary, ?int $from, ?int $to, string $camera, string $lens, string $keyword, int $ratingMin): bool {
		if (($summary['state'] ?? '') !== 'ready') return false;
		$captured = isset($summary['capturedAt']) ? (int)$summary['capturedAt'] : null;
		if ($from !== null && ($captured === null || $captured < $from)) return false;
		if ($to !== null && ($captured === null || $captured > $to)) return false;
		if ($camera !== '' && mb_stripos((string)($summary['camera'] ?? ''), $camera) === false) return false;
		if ($lens !== '' && mb_stripos((string)($summary['lens'] ?? ''), $lens) === false) return false;
		if ($keyword !== '') {
			$keywords = is_array($summary['keywords'] ?? null) ? implode("\n", $summary['keywords']) : '';
			if (mb_stripos($keywords, $keyword) === false) return false;
		}
		return (int)($summary['rating'] ?? 0) >= $ratingMin;
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
