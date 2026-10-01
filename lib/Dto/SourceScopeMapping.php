<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use OCP\Files\Folder;

final class SourceScopeMapping {
	/** @param list<array{folder: Folder, path: string, role: string}> $roots
	 * @param list<string> $missing
	 */
	public function __construct(public readonly bool $multiRoot, public readonly ?Folder $target, public readonly array $roots, public readonly array $missing) {
	}

	public function unavailable(): bool {
		return $this->multiRoot ? $this->roots === [] : $this->target === null;
	}
}
