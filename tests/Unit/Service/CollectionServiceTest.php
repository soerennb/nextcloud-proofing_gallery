<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Service\CollectionService;
use PHPUnit\Framework\TestCase;

final class CollectionServiceTest extends TestCase {
	public function testReplaceRejectsMoreThanMaximumBeforeTouchingStorage(): void {
		$service = (new \ReflectionClass(CollectionService::class))->newInstanceWithoutConstructor();
		$collection = new Gallery();
		$collection->setSourceType('collection');
		$items = array_fill(0, CollectionService::MAX_ITEMS + 1, [
			'sourceGalleryId' => 1,
			'fileId' => 1,
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('at most 1000');
		$service->replace($collection, 1, $items);
	}

	public function testReplaceRejectsFolderGalleryBeforeTouchingStorage(): void {
		$service = (new \ReflectionClass(CollectionService::class))->newInstanceWithoutConstructor();

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not a collection');
		$service->replace(new Gallery(), 1, []);
	}
}
