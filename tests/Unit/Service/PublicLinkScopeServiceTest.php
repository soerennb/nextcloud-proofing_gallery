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

	public function testMultiRootLinkAllowsSharedAndAssignedFoldersOnly(): void {
		$link = $this->link('', 'folder');
		$link->setAllowedRootList(['Allgemein', 'Kinder/Anna']);
		$service = new PublicLinkScopeService();

		self::assertTrue($service->visiblePath($link, 'Kinder'));
		self::assertTrue($service->visiblePath($link, 'Kinder/Anna'));
		self::assertTrue($service->contains($link, GallerySettings::defaults(), 'Allgemein/feier.jpg'));
		self::assertTrue($service->contains($link, GallerySettings::defaults(), 'Kinder/Anna/portrait.jpg'));
		self::assertFalse($service->contains($link, GallerySettings::defaults(), 'Kinder/Ben/portrait.jpg'));
		self::assertFalse($service->visiblePath($link, 'Kinder/Ben'));
	}

	public function testLegacyRootDetailsExposeNamesAndSafeDefaultRoles(): void {
		$link = $this->link('', 'folder');
		$link->setAllowedRootList(['Allgemein', 'Kinder/Anna']);

		self::assertSame([
			['path' => 'Allgemein', 'name' => 'Allgemein', 'role' => 'shared'],
			['path' => 'Kinder/Anna', 'name' => 'Anna', 'role' => 'shared'],
		], (new PublicLinkScopeService())->rootDetails($link));
	}

	private function link(string $startPath, string $viewMode): PublicLink {
		$link = new PublicLink();
		$link->setStartPath($startPath);
		$link->setViewMode($viewMode);
		return $link;
	}
}
