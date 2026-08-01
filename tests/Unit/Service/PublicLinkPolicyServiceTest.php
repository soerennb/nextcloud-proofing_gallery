<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

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
}
