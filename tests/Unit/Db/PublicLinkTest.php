<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Db;

use OCA\ProofingGallery\Db\PublicLink;
use PHPUnit\Framework\TestCase;

final class PublicLinkTest extends TestCase {
	public function testEmptyStartPathIsTrackedForInsert(): void {
		$link = new PublicLink();

		$link->setStartPath('');

		self::assertArrayHasKey('startPath', $link->getUpdatedFields());
		self::assertSame('', $link->getStartPath());
	}
}
