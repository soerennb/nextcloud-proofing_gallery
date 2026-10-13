<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\ProjectCreationOptions;
use OCA\ProofingGallery\Exception\ProjectCreationException;
use PHPUnit\Framework\TestCase;

final class ProjectCreationOptionsTest extends TestCase {

	public function testInvalidCombinationHasDedicatedException(): void {
		$this->expectException(ProjectCreationException::class);
		ProjectCreationOptions::assertValid('uploads', 'standard', 'collection');
	}

	public function testCustomIntegrationsRemainCompatible(): void {
		ProjectCreationOptions::assertValid('custom', 'standard', 'collection');
		self::addToAssertionCount(1);
	}
}
