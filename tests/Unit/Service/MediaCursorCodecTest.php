<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\MediaIndex;
use OCA\ProofingGallery\Dto\MediaIndexQuery;
use OCA\ProofingGallery\Service\MediaCursorCodec;
use PHPUnit\Framework\TestCase;

final class MediaCursorCodecTest extends TestCase {
	public function testCursorRoundTripsWithinTheSameQuery(): void {
		$query = $this->query(3);
		$entry = new MediaIndex();
		$entry->setFileId(17);
		$entry->setSortKey('portrait.jpg');
		$cursor = (new MediaCursorCodec())->encode($entry, $query);
		self::assertSame(['portrait.jpg', 17], (new MediaCursorCodec())->decode($cursor, $query));
	}

	public function testRatingChangeInvalidatesCursor(): void {
		$entry = new MediaIndex();
		$entry->setFileId(17);
		$entry->setSortKey('portrait.jpg');
		$codec = new MediaCursorCodec();
		$cursor = $codec->encode($entry, $this->query(3));
		$this->expectException(\InvalidArgumentException::class);
		$codec->decode($cursor, $this->query(4));
	}

	private function query(int $rating): MediaIndexQuery {
		return new MediaIndexQuery(4, 'owner', 60, 'portraits', 'ada', 'name', 'asc', $rating);
	}
}
