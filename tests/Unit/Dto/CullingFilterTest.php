<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Dto;

use OCA\ProofingGallery\Dto\CullingFilter;
use OCA\ProofingGallery\Dto\MediaIndexQuery;
use PHPUnit\Framework\TestCase;

final class CullingFilterTest extends TestCase {
	public function testEmptyMatchesRemainDifferentFromAnUnfilteredView(): void {
		$empty = CullingFilter::fromInput(null, null, null, '');
		$unfiltered = CullingFilter::fromInput(null, null, null, null);
		self::assertSame([], $empty->fileIds);
		self::assertNotSame($this->query($empty)->cursorScope(), $this->query($unfiltered)->cursorScope());
	}

	public function testNormalizedMembersKeepTheCursorStable(): void {
		$first = CullingFilter::fromInput(0, 'none', 'none', '3,1,3');
		$second = CullingFilter::fromInput(0, 'none', 'none', '1,3');
		self::assertSame([1, 3], $first->fileIds);
		self::assertSame($this->query($first)->cursorScope(), $this->query($second)->cursorScope());
		self::assertSame($first, $this->query($first)->withLimit(201)->culling);
	}

	public function testChangingOneFilterInvalidatesTheCursor(): void {
		self::assertNotSame($this->query(new CullingFilter(5))->cursorScope(), $this->query(new CullingFilter(4))->cursorScope());
	}

	public function testMalformedMemberIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		CullingFilter::fromInput(null, null, null, '1,2garbage');
	}

	private function query(CullingFilter $filter): MediaIndexQuery {
		return MediaIndexQuery::fromInput(1, 'owner', 200, '', '', 'name', 'asc', 0, culling: $filter);
	}
}
