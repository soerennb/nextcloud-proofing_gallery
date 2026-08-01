<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\CollaborationService;
use PHPUnit\Framework\TestCase;

final class CollaborationExportTest extends TestCase {
	public function testCsvUsesRfc4180QuotingAndCrlfForEdgeCases(): void {
		$service = (new \ReflectionClass(CollaborationService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod($service, 'csv');
		$content = $method->invoke($service, [
			['filename', 'comment'],
			['portrait, final.jpg', "Client said \"yes\"\nSecond line"],
		]);

		self::assertSame(
			"\"filename\",\"comment\"\r\n\"portrait, final.jpg\",\"Client said \"\"yes\"\"\nSecond line\"\r\n",
			$content,
		);
	}
}
