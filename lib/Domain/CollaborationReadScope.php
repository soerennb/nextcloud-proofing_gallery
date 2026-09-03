<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

use InvalidArgumentException;

final class CollaborationReadScope {
	private function __construct(
		private string $mode,
		private ?int $guestId = null,
		private ?string $actorUid = null,
	) {
		if ($mode === 'guest' && ($guestId ?? 0) < 1) {
			throw new InvalidArgumentException('A guest collaboration scope requires a guest ID');
		}
		if ($mode === 'user' && ($actorUid === null || $actorUid === '')) {
			throw new InvalidArgumentException('A user collaboration scope requires a user UID');
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

	public static function user(string $actorUid): self {
		return new self('user', null, $actorUid);
	}

	public function isEmpty(): bool {
		return $this->mode === 'none';
	}

	public function guestId(): ?int {
		return $this->mode === 'guest' ? $this->guestId : null;
	}

	public function actorUid(): ?string {
		return $this->mode === 'user' ? $this->actorUid : null;
	}
}
