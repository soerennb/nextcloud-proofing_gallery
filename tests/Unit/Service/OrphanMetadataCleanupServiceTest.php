<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\OrphanMetadataCleanupService;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrphanMetadataCleanupServiceTest extends TestCase {
	#[DataProvider('invalidScopes')]
	public function testInvalidScopeCannotReachTheDatabaseOrDeleteMetadata(int $storage, array $ids): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$metadata = $this->createMock(IFilesMetadataManager::class);
		$metadata->expects(self::never())->method('deleteMetadata');
		$this->expectException(\InvalidArgumentException::class);
		(new OrphanMetadataCleanupService($db, $metadata))->run($storage, $ids, true);
	}

	public static function invalidScopes(): array {
		return [[0, [1]], [1, [-1]], [1, [0]], [1, ['1']], [1, [true]], [1, [1.5]], [1, ['id' => 1]], [1, array_fill(0, 100001, 1)]];
	}
}
