<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\ProjectCreationOptions;
use OCA\ProofingGallery\Exception\ProjectCreationException;
use PHPUnit\Framework\TestCase;

final class ProjectCreationOptionsTest extends TestCase {
	public function testFourPhotoPurposesSupportSharedAndPrivateFolderWorkflows(): void {
		$options = ProjectCreationOptions::all();

		foreach (['delivery', 'showcase', 'selection', 'proofing'] as $purpose) {
			self::assertSame(['standard', 'event'], $options[$purpose]['deliveryModes']);
			self::assertSame(['existing', 'new', 'collection'], $options[$purpose]['sourceModes']['standard']);
			self::assertSame(['existing', 'new'], $options[$purpose]['sourceModes']['event']);
		}
	}

	public function testUploadsUseAFolderAndNeverEventOrCollectionDelivery(): void {
		$options = ProjectCreationOptions::all()['uploads'];

		self::assertSame(['standard'], $options['deliveryModes']);
		self::assertSame(['existing', 'new'], $options['sourceModes']['standard']);
		self::assertSame('new', $options['defaults']['sourceMode']);
	}

	public function testInvalidCombinationHasDedicatedException(): void {
		$this->expectException(ProjectCreationException::class);
		ProjectCreationOptions::assertValid('uploads', 'standard', 'collection');
	}

	public function testCustomIntegrationsRemainCompatible(): void {
		ProjectCreationOptions::assertValid('custom', 'standard', 'collection');
		self::addToAssertionCount(1);
	}
}
