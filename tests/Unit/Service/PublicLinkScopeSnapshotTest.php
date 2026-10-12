<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Service\PublicLinkScopeService;
use PHPUnit\Framework\TestCase;

final class PublicLinkScopeSnapshotTest extends TestCase {
	public function testRequestScopeKeepsItsResolvedRootsWithoutFollowingLaterChanges(): void {
		$link = new PublicLink();
		$link->setAllowedRoots('["Shared","Private"]');
		$scope = (new PublicLinkScopeService())->resolve($link);
		$link->setAllowedRoots('["Other"]');
		self::assertTrue($scope->contains('Private/photo.jpg', true));
		self::assertFalse($scope->contains('Other/photo.jpg', true));
		self::assertFalse($scope->contains('Private/.proofing-gallery-inbox/photo.jpg', true));
	}

	public function testFlatScopeCannotResolveAHiddenNestedFile(): void {
		$link = new PublicLink();
		$link->setStartPath('Portraits');
		$scope = (new PublicLinkScopeService())->resolve($link);
		self::assertTrue($scope->contains('Portraits/photo.jpg', false));
		self::assertFalse($scope->contains('Portraits/Nested/photo.jpg', false));
		self::assertTrue($scope->contains('Portraits/Nested/photo.jpg', true));
		self::assertSame('Portraits/Nested', $scope->indexPath('Nested'));
	}
}
