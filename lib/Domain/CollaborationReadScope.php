<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

use InvalidArgumentException;

final class CollaborationReadScope {
	private function __construct(
		private string $mode,
		private ?int $guestId = null,
	) {
		if ($mode === 'guest' && ($guestId ?? 0) < 1) {
			throw new InvalidArgumentException('A guest collaboration scope requires a guest ID');
		}
	}

	public static function all(): self {
		return new self('all');
	}

	public static function none(): self {
		return new self('none');
	}

	public static function guest(int $guestId): self {
		return new self('guest', $guestId);
	}

	public function isEmpty(): bool {
		return $this->mode === 'none';
	}

	public function guestId(): ?int {
		return $this->mode === 'guest' ? $this->guestId : null;
	}
}
