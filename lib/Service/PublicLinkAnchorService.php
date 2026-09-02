<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Creates empty native-share targets for disjoint public gallery scopes.
 *
 * Nextcloud shares can only point at one node. Sharing the gallery root for a
 * multi-root scope would expose excluded siblings through public WebDAV, so the
 * native share points at an empty app-owned folder while app routes resolve the
 * explicitly allowed folders from the gallery root.
 */
final class PublicLinkAnchorService {
	public function __construct(private IRootFolder $rootFolder) {
	}

	public function create(string $ownerUid): Folder {
		$userFolder = $this->rootFolder->getUserFolder($ownerUid);
		$appFolder = $this->folder($userFolder, '.proofing-gallery');
		$anchorFolder = $this->folder($appFolder, 'public-link-anchors');
		return $anchorFolder->newFolder(bin2hex(random_bytes(16)));
	}

	public function resolve(string $ownerUid, int $fileId): Folder {
		$userFolder = $this->rootFolder->getUserFolder($ownerUid);
		$expectedParent = rtrim($userFolder->getPath(), '/') . '/.proofing-gallery/public-link-anchors/';
		foreach ($userFolder->getById($fileId) as $node) {
			if ($node instanceof Folder && str_starts_with($node->getPath(), $expectedParent)) return $node;
		}
		throw new FolderAccessException('Public link scope anchor was not found');
	}

	public function delete(Folder $anchor): void {
		$anchor->delete();
	}

	private function folder(Folder $parent, string $name): Folder {
		try {
			$node = $parent->get($name);
			if (!$node instanceof Folder) throw new \RuntimeException('Reserved public link path is not a folder');
			return $node;
		} catch (\OCP\Files\NotFoundException) {
			return $parent->newFolder($name);
		}
	}
}
