<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use InvalidArgumentException;
use OCA\ProofingGallery\Domain\CollaborationReadScope;
use PHPUnit\Framework\TestCase;

final class CollaborationReadScopeTest extends TestCase {
	public function testScopesCannotConfuseAnonymousWithUnfilteredReads(): void {
		self::assertTrue(CollaborationReadScope::none()->isEmpty());
		self::assertNull(CollaborationReadScope::none()->guestId());
		self::assertFalse(CollaborationReadScope::all()->isEmpty());
		self::assertNull(CollaborationReadScope::all()->guestId());
		self::assertSame(42, CollaborationReadScope::guest(42)->guestId());
	}

	public function testGuestScopeRejectsInvalidIds(): void {
		$this->expectException(InvalidArgumentException::class);
		CollaborationReadScope::guest(0);
	}
}
