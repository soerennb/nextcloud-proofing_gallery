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
		$entry->setNaturalName('portrait.jpg');
		$cursor = (new MediaCursorCodec($this->createMock(\OCP\Security\ICrypto::class)))->encode($entry, $query);
		self::assertSame(['portrait.jpg', 17, 'next'], (new MediaCursorCodec($this->createMock(\OCP\Security\ICrypto::class)))->decode($cursor, $query));
		$previous = (new MediaCursorCodec($this->createMock(\OCP\Security\ICrypto::class)))->encode($entry, $query, 'previous');
		self::assertSame(['portrait.jpg', 17, 'previous'], (new MediaCursorCodec($this->createMock(\OCP\Security\ICrypto::class)))->decode($previous, $query));
	}

	public function testRatingChangeInvalidatesCursor(): void {
		$entry = new MediaIndex();
		$entry->setFileId(17);
		$entry->setSortKey('portrait.jpg');
		$entry->setNaturalName('portrait.jpg');
		$codec = new MediaCursorCodec($this->createMock(\OCP\Security\ICrypto::class));
		$cursor = $codec->encode($entry, $this->query(3));
		$this->expectException(\InvalidArgumentException::class);
		$codec->decode($cursor, $this->query(4));
	}

	public function testViewAndArrangementChangesInvalidateCursor(): void {
		$entry = new MediaIndex();
		$entry->setFileId(17);
		$entry->setSortKey('portrait.jpg');
		$entry->setNaturalName('portrait.jpg');
		$codec = new MediaCursorCodec($this->createMock(\OCP\Security\ICrypto::class));
		$cursor = $codec->encode($entry, $this->query(3));

		foreach ([
			new MediaIndexQuery(5, 'owner', 60, 'portraits', 'ada', 'name', 'asc', 3),
			new MediaIndexQuery(4, 'other-owner', 60, 'portraits', 'ada', 'name', 'asc', 3),
			new MediaIndexQuery(4, 'owner', 60, 'other', 'ada', 'name', 'asc', 3),
			new MediaIndexQuery(4, 'owner', 60, 'portraits', 'other', 'name', 'asc', 3),
			new MediaIndexQuery(4, 'owner', 60, 'portraits', 'ada', 'name', 'desc', 3),
		] as $query) {
			try {
				$codec->decode($cursor, $query);
				self::fail('A cursor must be bound to the complete view query');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	private function query(int $rating): MediaIndexQuery {
		return new MediaIndexQuery(4, 'owner', 60, 'portraits', 'ada', 'name', 'asc', $rating);
	}
}
