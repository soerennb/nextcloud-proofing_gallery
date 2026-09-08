<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Domain\CollaborationActor;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

final class CollaborationActorTest extends TestCase {
	public function testAuthenticatedUserOwnsOnlyRowsWithTheSameUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ncadmin');
		$user->method('getDisplayName')->willReturn('Nextcloud Admin');
		$actor = CollaborationActor::user($user);

		self::assertTrue($actor->owns(['guest_id' => null, 'actor_uid' => 'ncadmin']));
		self::assertFalse($actor->owns(['guest_id' => 7, 'actor_uid' => null]));
		self::assertFalse($actor->owns(['guest_id' => null, 'actor_uid' => 'other-user']));
		self::assertSame('user', $actor->jsonSerialize()['kind']);
		self::assertSame('ncadmin', $actor->jsonSerialize()['id']);
	}

	public function testGuestOwnsOnlyRowsWithTheSameGuestId(): void {
		$guest = new Guest();
		$guest->setId(7);
		$guest->setPublicId('guest-public-id');
		$guest->setDisplayName('Reviewer');
		$guest->setCreatedAt(100);
		$actor = CollaborationActor::guest($guest);

		self::assertTrue($actor->owns(['guest_id' => 7, 'actor_uid' => null]));
		self::assertFalse($actor->owns(['guest_id' => 8, 'actor_uid' => null]));
		self::assertFalse($actor->owns(['guest_id' => null, 'actor_uid' => 'ncadmin']));
		self::assertSame('guest', $actor->jsonSerialize()['kind']);
	}
}
