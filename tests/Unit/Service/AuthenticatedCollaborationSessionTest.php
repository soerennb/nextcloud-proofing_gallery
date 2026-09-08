<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Service\AuthenticatedCollaborationSession;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

final class AuthenticatedCollaborationSessionTest extends TestCase {
	public function testAccountUidAndNonceRemainStableForTheGallerySession(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ncadmin');
		$user->method('getDisplayName')->willReturn('Nextcloud Admin');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		$values = [];
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(static function (string $key) use (&$values): mixed { return $values[$key] ?? null; });
		$session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$values): void { $values[$key] = $value; });
		$random = $this->createMock(ISecureRandom::class);
		$random->expects(self::once())->method('generate')->willReturn(str_repeat('n', 64));
		$gallery = new Gallery();
		$gallery->setId(42);
		$service = new AuthenticatedCollaborationSession($users, $session, $random);

		$first = $service->current($gallery);
		$second = $service->current($gallery);

		self::assertNotNull($first);
		self::assertSame('ncadmin', $first['actor']->userUid());
		self::assertSame('ncadmin', $first['actor']->jsonSerialize()['id']);
		self::assertSame($first['nonce'], $second['nonce']);
		self::assertSame('ncadmin', $service->authenticate($gallery, $first['nonce'])?->userUid());
	}

	public function testInvalidNonceIsRejectedForAuthenticatedAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ncadmin');
		$user->method('getDisplayName')->willReturn('Nextcloud Admin');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn(str_repeat('n', 64));
		$random = $this->createMock(ISecureRandom::class);
		$gallery = new Gallery();
		$gallery->setId(42);

		$this->expectException(\InvalidArgumentException::class);
		(new AuthenticatedCollaborationSession($users, $session, $random))->authenticate($gallery, 'wrong');
	}
}
