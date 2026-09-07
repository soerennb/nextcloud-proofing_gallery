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

	public function testAccountCollaborationRowsAreRemovedByUid(): void {
		$source = file_get_contents(__DIR__ . '/../../../lib/Db/PurgeRepository.php');
		self::assertIsString($source);
		foreach (['proofing_feedback', 'proofing_comments', 'proofing_selections', 'proofing_guest_ratings', 'proofing_events', 'proofing_share_audit'] as $table) {
			self::assertStringContainsString("'" . $table . "'", $source);
		}
		self::assertStringContainsString("eq('actor_uid'", $source);
		self::assertStringContainsString("set('submitted_by_actor_uid'", $source);
	}

	public function testEncryptedPinHandoffsArePurgedBeforeTheirWaves(): void {
		self::assertLessThan(
			array_search('proofing_event_waves', PurgeRepository::TABLES, true),
			array_search('proofing_pin_handoffs', PurgeRepository::TABLES, true),
		);
	}
}
