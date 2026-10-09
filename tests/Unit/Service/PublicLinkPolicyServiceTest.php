<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\PublicLinkPolicyService;
use PHPUnit\Framework\TestCase;

final class PublicLinkPolicyServiceTest extends TestCase {
	public function testInheritedFeedbackChangesWhileCustomFeedbackAndOtherPermissionsStayStored(): void {
		$settings = GallerySettings::fromArray(['mode' => 'collaboration', 'review' => ['ratings' => true, 'pick' => true]]);
		$link = new \OCA\ProofingGallery\Db\PublicLink();
		$link->setPolicy(json_encode(['ratings' => false, 'pick' => false, 'downloadScope' => 'individual', 'upload' => false], JSON_THROW_ON_ERROR));
		$service = new PublicLinkPolicyService();
		self::assertFalse($service->forLink($settings, $link)->allows('ratings'));
		$link->setFeedbackPolicyMode('inherit');
		self::assertTrue($service->forLink($settings, $link)->allows('ratings'));
		self::assertTrue($service->forLink($settings, $link)->allows('pick'));
		self::assertSame('individual', $service->forLink($settings, $link)->downloadScope->value);
		self::assertFalse($service->forLink($settings, $link)->allows('upload'));
		self::assertFalse($service->forLink(GallerySettings::merge($settings, ['review' => ['ratings' => false]]), $link)->allows('ratings'));
		self::assertFalse(json_decode($link->getPolicy(), true)['ratings']);
	}

	public function testPermissionsAndNavigationResolveIndependentlyWithoutWritingTheEntity(): void {
		$settings = GallerySettings::fromArray(['delivery' => ['downloadScope' => 'all', 'guestUploads' => true], 'navigation' => ['recursive' => true, 'groupBy' => 'folder', 'groupDepth' => 4]]);
		$link = new \OCA\ProofingGallery\Db\PublicLink();
		$link->setPolicy(json_encode(['downloadScope' => 'none'], JSON_THROW_ON_ERROR));
		$link->setGroupDepth(2);
		$service = new PublicLinkPolicyService();
		self::assertSame('none', $service->forLink($settings, $link)->downloadScope->value);
		self::assertSame(['viewMode' => 'folder', 'groupDepth' => 2], $service->navigation($settings, $link));
		$link->setPermissionsPolicyMode('inherit');
		self::assertSame('all', $service->forLink($settings, $link)->downloadScope->value);
		self::assertTrue($service->forLink($settings, $link)->allows('export'));
		self::assertSame('folder', $service->navigation($settings, $link)['viewMode']);
		$link->setNavigationPolicyMode('inherit');
		$resolved = $service->resolvedLink($settings, $link);
		self::assertSame('recursive', $resolved->getViewMode());
		self::assertSame(4, $resolved->getGroupDepth());
		self::assertSame('folder', $link->getViewMode());
		self::assertSame(2, $link->getGroupDepth());
		self::assertSame('none', json_decode($link->getPolicy(), true)['downloadScope']);
	}

	public function testEffectiveNativeDownloadScopeUsesIntersectionRatherThanAnOrdering(): void {
		$service = new PublicLinkPolicyService();
		foreach (['none', 'individual', 'selection', 'all'] as $galleryScope) {
			foreach (['none', 'individual', 'selection', 'all'] as $linkScope) {
				$settings = GallerySettings::fromArray(['delivery' => ['downloadScope' => $galleryScope]]);
				$link = \OCA\ProofingGallery\Domain\PublicLinkPolicy::fromArray(['downloadScope' => $linkScope]);
				$effective = $service->effectiveDownloadScope($settings, $link);
				self::assertSame(in_array($galleryScope, ['individual', 'all'], true) && in_array($linkScope, ['individual', 'all'], true), $effective->allowsIndividual());
				self::assertSame(in_array($galleryScope, ['selection', 'all'], true) && in_array($linkScope, ['selection', 'all'], true), $effective->allowsSelection());
			}
		}
	}

	public function testPresetsRemainRestrictiveByDefault(): void {
		$presets = (new PublicLinkPolicyService())->presets();

		self::assertTrue($presets['presentation']['view']);
		self::assertFalse($presets['presentation']['ratings']);
		self::assertSame('none', $presets['presentation']['downloadScope']);
		self::assertTrue($presets['proofing']['ratings']);
		self::assertSame('all', $presets['delivery']['downloadScope']);
	}

	public function testRestrictionCannotWidenCapabilities(): void {
		$service = new PublicLinkPolicyService();
		$effective = $service->restrict(
			$service->presets()['delivery'],
			$service->presets()['selection'],
		);

		self::assertSame('none', $effective['downloadScope']);
		self::assertFalse($effective['export']);
		self::assertFalse($effective['comments']);
	}

	public function testUnknownPermissionIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new PublicLinkPolicyService())->validate(['trackVisitors' => true]);
	}

	public function testGallerySettingsBecomeTheEventRecipientPolicy(): void {
		$settings = GallerySettings::fromArray([
			'mode' => 'collaboration',
			'review' => ['likes' => false, 'comments' => true, 'annotations' => true, 'selections' => true],
			'delivery' => ['downloadScope' => 'selection', 'contactSheet' => false],
			'metadata' => ['publicFields' => ['copyright']],
		]);

		$policy = (new PublicLinkPolicyService())->forSettings($settings);

		self::assertFalse($policy['likes']);
		self::assertTrue($policy['comments']);
		self::assertTrue($policy['annotations']);
		self::assertTrue($policy['selections']);
		self::assertSame('selection', $policy['downloadScope']);
		self::assertTrue($policy['metadata']);
	}

	public function testContactSheetCannotBypassDisabledDownloads(): void {
		$settings = GallerySettings::fromArray([
			'mode' => 'presentation',
			'delivery' => ['downloadScope' => 'none', 'contactSheet' => true],
		]);

		$policy = (new PublicLinkPolicyService())->forSettings($settings);

		self::assertSame('none', $policy['downloadScope']);
		self::assertFalse($policy['export']);
	}
}
