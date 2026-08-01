<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Exception;

final class GalleryNotReadyException extends \InvalidArgumentException {
	/** @param array{ready: bool, revision: int, checks: list<array{code: string, state: string, action: string}>} $report */
	public function __construct(public readonly array $report) {
		parent::__construct('The gallery is not ready to publish');
	}
}
