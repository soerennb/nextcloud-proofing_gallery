<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\ScopedCursorCodec;
use PHPUnit\Framework\TestCase;

final class ScopedCursorCodecTest extends TestCase {
	public function testCursorRoundTripsOnlyInsideItsScope(): void {
		$codec = new ScopedCursorCodec();
		$cursor = $codec->encode('gallery:17:activity', 42);
		self::assertSame(42, $codec->decode($cursor, 'gallery:17:activity'));
		$this->expectException(\InvalidArgumentException::class);
		$codec->decode($cursor, 'gallery:18:activity');
	}

	public function testMalformedCursorIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new ScopedCursorCodec())->decode('not-a-cursor', 'scope');
	}
}
