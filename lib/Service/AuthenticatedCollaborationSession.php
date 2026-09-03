<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Domain\CollaborationActor;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/** Issues a same-origin nonce for collaboration actions made by a logged-in user. */
final class AuthenticatedCollaborationSession {
	public function __construct(
		private IUserSession $users,
		private ISession $session,
		private ISecureRandom $random,
	) {
	}

	public function actor(): ?CollaborationActor {
		$user = $this->users->getUser();
		return $user === null ? null : CollaborationActor::user($user);
	}

	/** @return array{actor: CollaborationActor, nonce: string}|null */
	public function current(Gallery $gallery): ?array {
		$actor = $this->actor();
		if ($actor === null) return null;
		$key = $this->key($gallery, $actor->userUid() ?? '');
		$nonce = $this->session->get($key);
		if (!is_string($nonce) || strlen($nonce) < 32) {
			$nonce = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->session->set($key, $nonce);
		}
		return ['actor' => $actor, 'nonce' => $nonce];
	}

	public function authenticate(Gallery $gallery, ?string $nonce): ?CollaborationActor {
		$current = $this->current($gallery);
		if ($current === null) return null;
		if ($nonce === null || $nonce === '' || !hash_equals($current['nonce'], $nonce)) {
			throw new InvalidArgumentException('Invalid request nonce');
		}
		return $current['actor'];
	}

	private function key(Gallery $gallery, string $uid): string {
		return 'proofing_gallery_actor_nonce:' . $gallery->getId() . ':' . hash('sha256', $uid);
	}
}
