<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Dto;

use OCA\ProofingGallery\Domain\DownloadScope;
use OCA\ProofingGallery\Domain\PublicLinkCapability;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use PHPUnit\Framework\TestCase;

final class PublicLinkConfigurationTest extends TestCase {
	public function testNormalizesAndTypesPublicLinkInput(): void {
		$config = PublicLinkConfiguration::fromArray([
			'name' => ' Client review ',
			'policy' => ['comments' => true, 'downloadScope' => 'selection'],
			'startPath' => ' Highlights ',
			'viewMode' => 'recursive',
			'groupDepth' => 2,
			'minOwnerRating' => 4,
			'publicLocale' => 'de',
			'expiresAt' => '2026-12-31',
			'reviewEnabled' => true,
			'reviewDueDate' => '2026-11-30',
		]);

		self::assertSame('Client review', $config->name);
		self::assertSame('Highlights', $config->startPath);
		self::assertTrue($config->policy->allows(PublicLinkCapability::Comments));
		self::assertSame(DownloadScope::Selection, $config->policy->downloadScope);
		self::assertSame('2026-12-31', $config->expiresAt?->format('Y-m-d'));
		self::assertTrue($config->reviewEnabled);
		self::assertSame('2026-11-30', $config->reviewDueDate);
	}

	public function testRejectsInvalidReviewConfiguration(): void {
		$this->expectException(\InvalidArgumentException::class);
		PublicLinkConfiguration::fromArray(['name' => 'Review', 'reviewEnabled' => true, 'reviewDueDate' => '30.11.2026']);
	}

	public function testRejectsLinksThatDisableViewing(): void {
		$this->expectException(\InvalidArgumentException::class);
		PublicLinkConfiguration::fromArray(['name' => 'Hidden', 'policy' => ['view' => false]]);
	}
}
