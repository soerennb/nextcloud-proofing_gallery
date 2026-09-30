<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\Files\Folder;

/** Native shares must use the same target as the public scope resolver. */
final class PublicShareTargetService {
	public function __construct(private FolderService $folders, private PublicLinkAnchorService $anchors, private PublicLinkScopeService $scopes) {
	}

	public function resolve(Gallery $gallery, PublicLink $link): Folder {
		try {
			if ($this->scopes->isMultiRoot($link)) {
				if ($link->getScopeAnchorId() === null) throw new \InvalidArgumentException('The public link scope anchor is missing');
				return $this->anchors->resolve($gallery->getOwnerUid(), $link->getScopeAnchorId());
			}
			$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
			$target = $link->getStartPath() === '' ? $root : $root->get($link->getStartPath());
			if (!$target instanceof Folder) throw new \InvalidArgumentException('The public link target is not a folder');
			return $target;
		} catch (FolderAccessException|\OCP\Files\NotFoundException $exception) {
			throw new \InvalidArgumentException('The public link target is unavailable. Repair its folder scope before recovering the share.', previous: $exception);
		}
	}
}
