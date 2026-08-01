<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\CullState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CullStateTest extends TestCase {
	public function testValidStateIsExplicitlyTyped(): void {
		$state = new CullState(4, 'green', 'pick', 'merge');

		self::assertSame(4, $state->rating);
		self::assertSame('green', $state->color);
		self::assertSame('pick', $state->pick);
		self::assertSame('merge', $state->source);
	}

	/** @return iterable<string, array{int, string, string, string}> */
	public static function invalidStates(): iterable {
		yield 'rating' => [6, 'none', 'none', 'app'];
		yield 'color' => [0, 'orange', 'none', 'app'];
		yield 'pick' => [0, 'none', 'maybe', 'app'];
		yield 'source' => [0, 'none', 'none', 'guest'];
	}

	#[DataProvider('invalidStates')]
	public function testInvalidStateIsRejected(int $rating, string $color, string $pick, string $source): void {
		$this->expectException(\InvalidArgumentException::class);

		new CullState($rating, $color, $pick, $source);
	}
}
