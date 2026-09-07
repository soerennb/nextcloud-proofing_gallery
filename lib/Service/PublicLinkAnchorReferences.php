<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

interface PublicLinkAnchorReferences {
	public function isAnchorReferenced(int $folderId): bool;
}
