<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use OCA\ProofingGallery\Service\PublicLinkInheritanceService;
use OCA\ProofingGallery\Service\PublicLinkPolicyService;
use PHPUnit\Framework\TestCase;

final class PublicLinkInheritanceServiceTest extends TestCase {
	private function gallery(): Gallery {
		$gallery = new Gallery();
		$gallery->setDeliveryMode('standard');
		$gallery->setSettings(json_encode(GallerySettings::fromArray([
			'delivery' => ['downloadScope' => 'all', 'guestUploads' => true],
			'navigation' => ['recursive' => true, 'groupBy' => 'folder', 'groupDepth' => 3],
		]), JSON_THROW_ON_ERROR));
		return $gallery;
	}

	private function link(): PublicLink {
		$link = new PublicLink();
		$link->setIsPrimary(true);
		$link->setPermissionsPolicyMode('inherit');
		$link->setNavigationPolicyMode('inherit');
		return $link;
	}

	public function testExplicitInheritanceWinsOverStaleDraftValuesAndPreservesFeedback(): void {
		$result = (new PublicLinkInheritanceService(new PublicLinkPolicyService()))->configure($this->gallery(), $this->link(), PublicLinkConfiguration::fromArray([
			'name' => 'Primary', 'policy' => ['comments' => false], 'permissionsPolicyMode' => 'inherit', 'navigationPolicyMode' => 'inherit',
		]));
		self::assertSame('all', $result->policy->downloadScope->value);
		self::assertTrue($result->policy->allows('upload'));
		self::assertTrue($result->policy->allows('export'));
		self::assertFalse($result->policy->allows('comments'));
		self::assertSame('recursive', $result->viewMode);
		self::assertSame(3, $result->groupDepth);
	}

	public function testOlderClientsChangeOnlyTheEditedAreaToCustom(): void {
		$result = (new PublicLinkInheritanceService(new PublicLinkPolicyService()))->configure($this->gallery(), $this->link(), PublicLinkConfiguration::fromArray([
			'name' => 'Renamed', 'policy' => ['downloadScope' => 'none', 'upload' => false], 'viewMode' => 'recursive', 'groupDepth' => 3,
		]));
		self::assertSame('custom', $result->permissionsPolicyMode);
		self::assertSame('inherit', $result->navigationPolicyMode);
		self::assertSame('none', $result->policy->downloadScope->value);
		self::assertFalse($result->policy->allows('export'));
	}

	public function testOlderClientsRenameWithoutLosingInheritance(): void {
		$result = (new PublicLinkInheritanceService(new PublicLinkPolicyService()))->configure($this->gallery(), $this->link(), PublicLinkConfiguration::fromArray([
			'name' => 'Renamed', 'policy' => ['downloadScope' => 'all', 'upload' => true, 'export' => true], 'viewMode' => 'recursive', 'groupDepth' => 3,
		]));
		self::assertSame('inherit', $result->permissionsPolicyMode);
		self::assertSame('inherit', $result->navigationPolicyMode);
	}

	public function testCustomAutomaticDepthIsFrozenAtSave(): void {
		$link = $this->link(); $link->setNavigationPolicyMode('custom');
		$result = (new PublicLinkInheritanceService(new PublicLinkPolicyService()))->configure($this->gallery(), $link, PublicLinkConfiguration::fromArray(['name' => 'Own settings']));
		self::assertSame(3, $result->groupDepth);
		self::assertSame('folder', $result->viewMode);
	}

	public function testSecondaryAndEventLinksCannotInherit(): void {
		$service = new PublicLinkInheritanceService(new PublicLinkPolicyService());
		foreach (['secondary', 'event'] as $case) {
			$gallery = $this->gallery(); $link = $this->link();
			if ($case === 'secondary') $link->setIsPrimary(false); else $gallery->setDeliveryMode('event');
			try {
				$service->configure($gallery, $link, PublicLinkConfiguration::fromArray(['name' => 'Restricted', 'permissionsPolicyMode' => 'inherit']));
				self::fail('Restricted link inherited permissions');
			} catch (\InvalidArgumentException $error) { self::assertStringContainsString('standard primary', $error->getMessage()); }
		}
	}

	public function testMultiFolderScopeDisablesImplicitNavigationInheritance(): void {
		$service = new PublicLinkInheritanceService(new PublicLinkPolicyService());
		$result = $service->configure($this->gallery(), $this->link(), PublicLinkConfiguration::fromArray(['name' => 'Scoped', 'allowedRoots' => ['A']]));
		self::assertSame('custom', $result->navigationPolicyMode);
		$this->expectException(\InvalidArgumentException::class);
		$service->configure($this->gallery(), $this->link(), PublicLinkConfiguration::fromArray(['name' => 'Scoped', 'allowedRoots' => ['A'], 'navigationPolicyMode' => 'inherit']));
	}
}
