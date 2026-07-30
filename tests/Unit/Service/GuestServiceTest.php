<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\GuestService;
use PHPUnit\Framework\TestCase;

final class GuestServiceTest extends TestCase {
	public function testSessionSecretsAreOneWayHashed(): void {
		$hash = GuestService::hashSecret('session-secret');

		self::assertSame(64, strlen($hash));
		self::assertNotSame('session-secret', $hash);
		self::assertSame($hash, GuestService::hashSecret('session-secret'));
	}

	public function testMutationNonceMustMatchExactly(): void {
		$hash = GuestService::hashSecret('correct-nonce');

		self::assertTrue(GuestService::nonceMatches($hash, 'correct-nonce'));
		self::assertFalse(GuestService::nonceMatches($hash, 'wrong-nonce'));
		self::assertFalse(GuestService::nonceMatches($hash, 'correct-nonce '));
	}
}
