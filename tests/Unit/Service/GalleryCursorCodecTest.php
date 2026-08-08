<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\GalleryCursorCodec;
use PHPUnit\Framework\TestCase;

final class GalleryCursorCodecTest extends TestCase {
	public function testRoundTripPreservesStableKeyset(): void {
		$scope = ['archived' => false, 'search' => 'wedding', 'mode' => null];
		$codec = new GalleryCursorCodec();
		$cursor = $codec->encode('updated', 1234, 99, $scope);

		self::assertSame(['value' => 1234, 'id' => 99], $codec->decode($cursor, 'updated', $scope));
	}

	public function testTitleCursorKeepsNormalizedString(): void {
		$codec = new GalleryCursorCodec();
		$cursor = $codec->encode('title', 'familie müller', 42, ['archived' => true]);

		self::assertSame(['value' => 'familie müller', 'id' => 42], $codec->decode($cursor, 'title', ['archived' => true]));
	}

	public function testCursorCannotBeReusedWithOtherFilters(): void {
		$codec = new GalleryCursorCodec();
		$cursor = $codec->encode('updated', 1234, 99, ['archived' => false]);

		$this->expectException(\InvalidArgumentException::class);
		$codec->decode($cursor, 'updated', ['archived' => true]);
	}

	public function testMalformedCursorIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new GalleryCursorCodec())->decode('not-json', 'updated', []);
	}
}
