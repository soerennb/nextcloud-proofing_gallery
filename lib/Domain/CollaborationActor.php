<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Guest;
use OCP\IUser;

/**
 * The authenticated principal behind public-gallery collaboration writes.
 * Exactly one of guestId or userUid is present.
 */
final class CollaborationActor implements \JsonSerializable {
	private function __construct(
		private ?int $guestId,
		private ?string $userUid,
		private string $publicId,
		private string $displayName,
		private int $createdAt,
	) {
		if (($guestId === null) === ($userUid === null)) {
			throw new InvalidArgumentException('A collaboration actor must be either a guest or a user');
		}
	}

	public static function guest(Guest $guest): self {
		return new self(
			(int)$guest->getId(),
			null,
			$guest->getPublicId(),
			$guest->getDisplayName(),
			$guest->getCreatedAt(),
		);
	}

	public static function user(IUser $user): self {
		return new self(null, $user->getUID(), $user->getUID(), $user->getDisplayName(), 0);
	}

	public function guestId(): ?int {
		return $this->guestId;
	}

	public function userUid(): ?string {
		return $this->userUid;
	}

	public function isGuest(): bool {
		return $this->guestId !== null;
	}

	/** @param array<string, mixed> $row */
	public function owns(array $row): bool {
		return $this->guestId !== null
			? $row['guest_id'] !== null && (int)$row['guest_id'] === $this->guestId
			: $row['actor_uid'] !== null && hash_equals($this->userUid ?? '', (string)$row['actor_uid']);
	}

	/** @return array{id: string, kind: 'guest'|'user', displayName: string, createdAt: int} */
	public function jsonSerialize(): array {
		return [
			'id' => $this->publicId,
			'kind' => $this->isGuest() ? 'guest' : 'user',
			'displayName' => $this->displayName,
			'createdAt' => $this->createdAt,
		];
	}
}
