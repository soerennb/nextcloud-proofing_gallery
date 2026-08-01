<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\PublicLinkScopeService;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

final class PublicLinkScopeServiceTest extends TestCase {
	public function testStartPathCannotBeEscaped(): void {
		$link = $this->link('clients/acme', 'recursive');
		$service = new PublicLinkScopeService();
		self::assertSame('clients/acme/finals', $service->indexPath($link, 'finals'));
		$this->expectException(NotFoundException::class);
		$service->indexPath($link, '../private');
	}

	public function testMediaOutsideStartPathIsAlwaysRejected(): void {
		$link = $this->link('clients/acme', 'recursive');
		$service = new PublicLinkScopeService();
		self::assertTrue($service->contains($link, GallerySettings::defaults(), 'clients/acme/finals/a.jpg'));
		self::assertFalse($service->contains($link, GallerySettings::defaults(), 'clients/other/private.jpg'));
		self::assertFalse($service->contains($link, GallerySettings::defaults(), 'clients/acme/../private.jpg'));
	}

	public function testNonRecursiveLinkWithoutFolderNavigationAllowsOnlyDirectFiles(): void {
		$link = $this->link('clients/acme', 'folder');
		$settings = GallerySettings::merge(GallerySettings::defaults(), ['navigation' => ['folders' => false]]);
		$service = new PublicLinkScopeService();
		self::assertTrue($service->contains($link, $settings, 'clients/acme/a.jpg'));
		self::assertFalse($service->contains($link, $settings, 'clients/acme/finals/a.jpg'));
	}

	private function link(string $startPath, string $viewMode): PublicLink {
		$link = new PublicLink();
		$link->setStartPath($startPath);
		$link->setViewMode($viewMode);
		return $link;
	}
}
