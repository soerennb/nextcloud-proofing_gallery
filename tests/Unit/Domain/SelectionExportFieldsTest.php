<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\SelectionExportFields;
use PHPUnit\Framework\TestCase;

final class SelectionExportFieldsTest extends TestCase {
	public function testRatingRestrictionsDoNotBlockFilenameExports(): void {
		self::assertSame(['filename', 'comments'], SelectionExportFields::owner(['filename', 'guestAverage', 'guestCount', 'comments'], false));
		self::assertSame(['filename'], SelectionExportFields::reviewer(['filename', 'rating', 'pick'], ['ratings' => false, 'pick' => false]));
		self::assertSame(['filename'], SelectionExportFields::owner(['guestAverage'], false));
	}

	public function testFieldsRetainRequestedOrderAndRespectIndependentPermissions(): void {
		self::assertSame(['pick', 'filename'], SelectionExportFields::reviewer(['pick', 'rating', 'filename', 'pick', 'ownerRating'], ['ratings' => false, 'pick' => true]));
		self::assertSame(['rating'], SelectionExportFields::reviewer(['rating', 'pick'], ['ratings' => true, 'pick' => false]));
	}
}
