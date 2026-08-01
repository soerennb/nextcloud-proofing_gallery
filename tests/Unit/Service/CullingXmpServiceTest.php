<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\CullingXmpResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CullingXmpServiceTest extends TestCase {
	/** @return iterable<string, array{string, array<string, string>, array<string, int|string>}> */
	public static function resolutionModes(): iterable {
		yield 'report leaves app untouched' => ['report', [], ['rating' => 5, 'color' => 'red', 'pick' => 'pick']];
		yield 'app keeps app values' => ['app', [], ['rating' => 5, 'color' => 'red', 'pick' => 'pick']];
		yield 'xmp imports all fields' => ['xmp', [], ['rating' => 2, 'color' => 'blue', 'pick' => 'reject']];
		yield 'merge resolves each field independently' => ['merge', ['rating' => 'xmp', 'color' => 'app', 'pick' => 'xmp'], ['rating' => 2, 'color' => 'red', 'pick' => 'reject']];
	}

	/** @param array<string, string> $choices @param array<string, int|string> $expected */
	#[DataProvider('resolutionModes')]
	public function testEveryResolutionMode(string $mode, array $choices, array $expected): void {
		$result = (new CullingXmpResolver())->resolve($mode,
			['fileId' => 7, 'rating' => 5, 'color' => 'red', 'pick' => 'pick', 'revision' => 3],
			['exists' => true, 'rating' => 2, 'color' => 'blue', 'pick' => 'reject'],
			$choices,
		);
		self::assertSame($expected, array_intersect_key($result, ['rating' => true, 'color' => true, 'pick' => true]));
	}
}
