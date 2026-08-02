<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\SemanticVectorizer;
use PHPUnit\Framework\TestCase;

final class SemanticVectorizerTest extends TestCase {
	public function testMultilingualAliasesImproveRelevantMatches(): void {
		$vectors = new SemanticVectorizer();
		$query = $vectors->embed('Familie bei Sonnenuntergang');

		$family = $vectors->similarity($query, $vectors->embed('family portrait sunset children'));
		$vehicle = $vectors->similarity($query, $vectors->embed('industrial car detail'));

		self::assertGreaterThan($vehicle, $family);
		self::assertContains('familie', $vectors->concepts('Familie Familie Hochzeit'));
	}
}
