<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\PublicLinkPolicyService;
use PHPUnit\Framework\TestCase;

final class PublicLinkPolicyServiceTest extends TestCase {
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
