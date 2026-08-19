<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\DownloadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DownloadScopeTest extends TestCase {
	/** @return iterable<string, array{DownloadScope, DownloadScope, DownloadScope}> */
	public static function intersections(): iterable {
		yield 'all keeps individual' => [DownloadScope::All, DownloadScope::Individual, DownloadScope::Individual];
		yield 'all keeps selection' => [DownloadScope::All, DownloadScope::Selection, DownloadScope::Selection];
		yield 'incompatible scopes deny' => [DownloadScope::Individual, DownloadScope::Selection, DownloadScope::None];
		yield 'none always denies' => [DownloadScope::None, DownloadScope::All, DownloadScope::None];
	}

	#[DataProvider('intersections')]
	public function testRestrictionIsAnIntersection(DownloadScope $left, DownloadScope $right, DownloadScope $expected): void {
		self::assertSame($expected, $left->restrict($right));
		self::assertSame($expected, $right->restrict($left));
	}

	public function testOnlyAllAllowsWholeGalleryDownloads(): void {
		foreach (DownloadScope::cases() as $scope) {
			self::assertSame($scope === DownloadScope::All, $scope->allowsGallery());
		}
	}
}
