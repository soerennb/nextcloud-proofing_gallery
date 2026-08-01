<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use OCA\ProofingGallery\Domain\CullState;
use OCP\Files\File;

final class CullUpdateCommand {
	public function __construct(
		public readonly File $file,
		public readonly int $fileId,
		public readonly int $expectedRevision,
		public readonly CullState $state,
		public readonly ?string $sidecarEtag = null,
	) {
	}
}
