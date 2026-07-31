<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

interface CollectionAnchorReferences {
	public function isReferenced(int $folderId): bool;
}
