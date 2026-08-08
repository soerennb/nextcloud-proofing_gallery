<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Db;

use OCA\ProofingGallery\Db\PurgeRepository;
use PHPUnit\Framework\TestCase;

final class PurgeRepositoryContractTest extends TestCase {
	public function testGalleryParentIsDeletedAfterEveryChildTable(): void {
		self::assertSame('proofing_galleries', PurgeRepository::TABLES[array_key_last(PurgeRepository::TABLES)]);
	}

	public function testFolderScopedOwnerCullingDataIsNotDeletedWithOneGallery(): void {
		self::assertNotContains('proofing_media_cull', PurgeRepository::TABLES);
	}
}
