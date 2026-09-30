<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Exception\PublicShareMissingException;
use OCA\ProofingGallery\Service\PublicShareRecoveryService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PublicShareRecoveryServiceTest extends TestCase {
	private IManager $shares;
	private IUserManager $users;
	private ILockingProvider $locks;
	private LoggerInterface $logger;
	private Gallery $gallery;
	private PublicLink $link;

	protected function setUp(): void {
		$this->shares = $this->createMock(IManager::class);
		$this->shares->method('shareApiEnabled')->willReturn(true);
		$this->shares->method('shareApiAllowLinks')->willReturn(true);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturn($this->createMock(IUser::class));
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->gallery = new Gallery();
		$this->gallery->setOwnerUid('owner');
		$this->link = new PublicLink();
		$this->link->setCoreShareId(17);
		$this->link->setToken('previous-token');
	}

	public function testOnlyAbsentNativeRowsOfferRecovery(): void {
		$this->shares->expects(self::never())->method('createShare');
		$this->expectException(PublicShareMissingException::class);
		$this->service(false)->resolve($this->gallery, $this->link, 'previous-token', 9);
	}

	public function testValidMatchingSharesRetainNativeValidityChecks(): void {
		$share = $this->share();
		$this->shares->expects(self::once())->method('getShareById')->with('ocinternal:17', null, false)->willReturn($share);
		$this->shares->expects(self::once())->method('getShareByToken')->with('previous-token')->willReturn($share);
		self::assertSame($share, $this->service(17)->resolve($this->gallery, $this->link, 'previous-token', 9));
	}

	#[DataProvider('invalidNativeShares')]
	public function testExistingInvalidSharesCannotBeRecreated(string $field, mixed $value): void {
		$share = $this->share([$field => $value]);
		$this->shares->method('getShareById')->willReturn($share);
		$this->shares->expects(self::never())->method('getShareByToken');
		$this->shares->expects(self::never())->method('createShare');
		$this->expectException(\InvalidArgumentException::class);
		$this->service(17)->resolve($this->gallery, $this->link, 'previous-token', 9);
	}

	public static function invalidNativeShares(): array {
		return [['getSharedBy', 'another-owner'], ['getToken', 'another-token'], ['getNodeId', 99], ['getShareType', IShare::TYPE_USER], ['isExpired', true]];
	}

	public function testAdministrativeRestrictionsDoNotOfferRecovery(): void {
		$this->shares->method('sharingDisabledForUser')->willReturn(true);
		$this->expectException(PolicyViolationException::class);
		$this->service(false)->resolve($this->gallery, $this->link, 'previous-token', 9);
	}

	public function testMissingSharesCanBeRevokedWithoutNativeDeletion(): void {
		$this->shares->expects(self::never())->method('deleteShare');
		$this->service(false)->revoke($this->gallery, $this->link);
	}

	public function testRevocationRejectsAnotherOwnersShare(): void {
		$this->shares->method('getShareById')->willReturn($this->share(['getSharedBy' => 'someone-else']));
		$this->shares->expects(self::never())->method('deleteShare');
		$this->expectException(\InvalidArgumentException::class);
		$this->service(17)->revoke($this->gallery, $this->link);
	}

	public function testRestorePreviousTokenWhenPolicyAllows(): void {
		$share = $this->share();
		$share->expects(self::once())->method('setNoExpirationDate')->with(true);
		$share->expects(self::once())->method('setToken')->with('previous-token');
		$this->shares->method('createShare')->willReturn($share);
		$this->shares->method('allowCustomTokens')->willReturn(true);
		$this->shares->expects(self::once())->method('updateShare')->with($share)->willReturn($share);
		self::assertSame($share, $this->service(false)->create($share, 'previous-token'));
	}

	#[DataProvider('replacementTokens')]
	public function testTokenRestrictionsKeepTheGeneratedToken(bool $customAllowed, bool $usernameExists, int|false $occupied, string $previous): void {
		$share = $this->share();
		$this->shares->method('createShare')->willReturn($share);
		$this->shares->method('allowCustomTokens')->willReturn($customAllowed);
		$this->users->method('userExists')->willReturn($usernameExists);
		$share->expects(self::never())->method('setToken');
		$this->shares->expects(self::never())->method('updateShare');
		self::assertSame($share, $this->service($occupied)->create($share, $previous));
	}

	public static function replacementTokens(): array {
		return [[false, false, false, 'old'], [true, true, false, 'old'], [true, false, 18, 'old'], [true, false, false, 'invalid/token']];
	}

	public function testExpiryPolicyRejectsAnUnprotectedReplacementBeforeCreation(): void {
		$this->shares->method('shareApiLinkDefaultExpireDateEnforced')->willReturn(true);
		$this->shares->expects(self::never())->method('createShare');
		$this->expectException(\InvalidArgumentException::class);
		$this->service(false)->create($this->share(), 'old');
	}

	public function testFailedDeletionDisablesTheReplacement(): void {
		$share = $this->share();
		$this->shares->method('deleteShare')->willThrowException(new \RuntimeException('delete failed'));
		$share->expects(self::once())->method('setPermissions')->with(0);
		$this->shares->expects(self::once())->method('updateShare')->with($share)->willReturn($share);
		$this->logger->expects(self::once())->method('error');
		self::assertFalse($this->service(false)->discard($share, new \RuntimeException('persist failed')));
	}

	public function testCleanupFailuresDoNotReplaceTheOriginalError(): void {
		$share = $this->share();
		$original = new \RuntimeException('token update failed');
		$this->shares->method('createShare')->willReturn($share);
		$this->shares->method('allowCustomTokens')->willReturn(true);
		$this->shares->method('updateShare')->willThrowException($original);
		$this->shares->method('deleteShare')->willThrowException(new \RuntimeException('delete failed'));
		$this->logger->expects(self::exactly(2))->method('error');
		try { $this->service(false)->create($share, 'old'); self::fail('Expected the token update failure'); }
		catch (\RuntimeException $exception) { self::assertSame($original, $exception); }
	}

	public function testNestedOperationsKeepOneLockAndAlwaysReleaseIt(): void {
		$this->locks->expects(self::once())->method('acquireLock')->with('proofing-gallery:public-shares:8', ILockingProvider::LOCK_EXCLUSIVE);
		$this->locks->expects(self::once())->method('releaseLock')->with('proofing-gallery:public-shares:8', ILockingProvider::LOCK_EXCLUSIVE);
		$service = $this->service(false);
		$this->expectException(\RuntimeException::class);
		$service->locked(8, fn () => $service->locked(8, static function (): never { throw new \RuntimeException('operation failed'); }));
	}

	public function testLockContentionDoesNotRunTheOperation(): void {
		$this->locks->method('acquireLock')->willThrowException(new LockedException('busy'));
		$this->locks->expects(self::never())->method('releaseLock');
		$this->expectException(GalleryConflictException::class);
		$this->service(false)->locked(8, static fn () => self::fail('Must not run'));
	}

	private function share(array $overrides = []): IShare {
		$share = $this->createMock(IShare::class);
		foreach (array_replace(['getShareType' => IShare::TYPE_LINK, 'getSharedBy' => 'owner', 'getToken' => 'previous-token', 'getNodeId' => 9, 'isExpired' => false], $overrides) as $method => $value) $share->method($method)->willReturn($value);
		return $share;
	}

	private function service(int|false $row): PublicShareRecoveryService {
		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'setMaxResults'] as $method) $query->method($method)->willReturnSelf();
		$expressions = $this->createMock(IExpressionBuilder::class);
		$expressions->method('eq')->willReturn('condition');
		$query->method('expr')->willReturn($expressions);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($row);
		$query->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($query);
		return new PublicShareRecoveryService($this->shares, $db, $this->locks, $this->users, $this->logger);
	}
}
