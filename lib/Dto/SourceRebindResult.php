<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use OCA\ProofingGallery\Db\Gallery;

final class SourceRebindResult {
	/** @param list<array{linkId: int, linkName: string, path: string}> $missingScopes
	 * @param list<int> $suspendedLinkIds
	 */
	public function __construct(public readonly Gallery $gallery, public readonly array $missingScopes, public readonly array $suspendedLinkIds) {
	}

	/** @return array{missingScopes: list<array{linkId: int, linkName: string, path: string}>, suspendedLinkIds: list<int>} */
	public function report(): array {
		return ['missingScopes' => $this->missingScopes, 'suspendedLinkIds' => $this->suspendedLinkIds];
	}
}
