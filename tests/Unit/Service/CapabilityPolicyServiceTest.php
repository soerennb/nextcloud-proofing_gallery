<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Service\CapabilityPolicyService;
use OCA\ProofingGallery\Service\CoreSharingPolicyService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\IConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

final class CapabilityPolicyServiceTest extends TestCase {
	public function testCoreSharingRulesCannotBeWeakenedByTheApp(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $app === 'core' && $key === 'shareapi_allow_links' ? 'no' : $default,
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$service = new CapabilityPolicyService(new PolicyService($config), new CoreSharingPolicyService($config), $groups);

		self::assertFalse($service->feature('publicPublishing'));
		$this->expectException(PolicyViolationException::class);
		$service->assertCanPublish('photographer');
	}

	public function testCreatorGroupRestrictsNormalUsersButNotAdministrators(): void {
		$document = json_encode([
			'access' => ['creatorGroups' => ['photographers'], 'publisherGroups' => []],
		], JSON_THROW_ON_ERROR);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $key === 'instanceSettingsV2' ? $document : $default,
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturn(false);
		$service = new CapabilityPolicyService(new PolicyService($config), new CoreSharingPolicyService($config), $groups);

		$service->assertCanCreate('admin');
		$this->expectException(PolicyViolationException::class);
		$service->assertCanCreate('viewer');
	}
}
