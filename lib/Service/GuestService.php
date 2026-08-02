<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Db\GuestMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

final class GuestService {
	public const COOKIE_NAME = 'proofing_gallery_guest';
	private const LIFETIME_SECONDS = 2592000;
	private const MAX_ACTIVE_SESSIONS_PER_GALLERY = 10000;

	public function __construct(
		private GuestMapper $guests,
		private ISecureRandom $random,
		private ICrypto $crypto,
		private ITimeFactory $clock,
	) {
	}

	/** @return array{guest: Guest, secret: string, nonce: string} */
	public function create(Gallery $gallery, string $displayName = '', ?string $email = null): array {
		$now = $this->clock->getTime();
		if ($this->guests->countActiveForGallery($gallery->getId(), $now) >= self::MAX_ACTIVE_SESSIONS_PER_GALLERY) {
			throw new InvalidArgumentException('Guest session limit reached');
		}
		$displayName = trim($displayName);
		if (mb_strlen($displayName) > 120) {
			throw new InvalidArgumentException('Display name may contain at most 120 characters');
		}
		if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException('Email address is invalid');
		}

		$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$nonce = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$publicId = $this->uuid();

		$guest = new Guest();
		$guest->setGalleryId($gallery->getId());
		$guest->setPublicId($publicId);
		$guest->setSessionHash(self::hashSecret($secret));
		$guest->setNonceHash(self::hashSecret($nonce));
		$guest->setDisplayName($displayName !== '' ? $displayName : 'Guest ' . substr($publicId, 0, 6));
		$guest->setEmailCipher($email !== null && $email !== '' ? $this->crypto->encrypt(mb_strtolower($email)) : null);
		$guest->setCreatedAt($now);
		$guest->setLastSeenAt($now);
		$guest->setExpiresAt($now + self::LIFETIME_SECONDS);

		return ['guest' => $this->guests->insert($guest), 'secret' => $secret, 'nonce' => $nonce];
	}

	/** @throws DoesNotExistException */
	public function authenticate(Gallery $gallery, ?string $secret, ?string $nonce = null): Guest {
		if ($secret === null || $secret === '') {
			throw new DoesNotExistException('Guest session not found');
		}
		$guest = $this->guests->findBySession($gallery->getId(), self::hashSecret($secret), $this->clock->getTime());
		if ($nonce !== null && !self::nonceMatches($guest->getNonceHash(), $nonce)) {
			throw new InvalidArgumentException('Invalid request nonce');
		}

		$now = $this->clock->getTime();
		if ($now - $guest->getLastSeenAt() >= 300) {
			$guest->setLastSeenAt($now);
			$this->guests->update($guest);
		}
		return $guest;
	}

	public function delete(Guest $guest): void {
		$this->guests->delete($guest);
	}

	public function purgeExpired(): int {
		return $this->guests->deleteExpired($this->clock->getTime());
	}

	public static function hashSecret(string $secret): string {
		return hash('sha256', $secret);
	}

	public static function nonceMatches(string $storedHash, string $providedNonce): bool {
		return hash_equals($storedHash, self::hashSecret($providedNonce));
	}

	private function uuid(): string {
		$hex = bin2hex(random_bytes(16));
		return sprintf(
			'%s-%s-4%s-%s%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 13, 3),
			dechex((hexdec($hex[16]) & 0x3) | 0x8),
			substr($hex, 17, 3),
			substr($hex, 20, 12),
		);
	}
}
