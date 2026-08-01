<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\CsvEncoder;
use PHPUnit\Framework\TestCase;

final class CollaborationExportTest extends TestCase {
	public function testCsvUsesRfc4180QuotingAndCrlfForEdgeCases(): void {
		$content = (new CsvEncoder())->encode([
			['filename', 'comment'],
			['portrait, final.jpg', "Client said \"yes\"\nSecond line"],
		]);

		self::assertSame(
			"\"filename\",\"comment\"\r\n\"portrait, final.jpg\",\"Client said \"\"yes\"\"\nSecond line\"\r\n",
			$content,
		);
	}
}
