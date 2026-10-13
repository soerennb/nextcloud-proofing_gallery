<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Integration;

use OCA\ProofingGallery\Capabilities;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class IntegrationContractTest extends TestCase {
	public function testCapabilitiesGateVersionSpecificIntegrations(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('32.0.0');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $value): string => $value);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $appId): bool => in_array($appId, ['context_chat', 'related_resources', 'workflowengine'], true));

		$capabilities = (new Capabilities($config, $l10n, $apps))->getCapabilities();

		self::assertSame(2, $capabilities['proofing_gallery']['agent_api_version']);
		self::assertTrue($capabilities['proofing_gallery']['integrations']['review_workflow']);
		self::assertFalse($capabilities['proofing_gallery']['integrations']['files_sidebar']);
		self::assertTrue($capabilities['proofing_gallery']['integrations']['context_chat']);
		self::assertFalse($capabilities['proofing_gallery']['integrations']['context_agent']);
		self::assertSame('experimental', $capabilities['proofing_gallery']['integrations']['context_agent_maturity']);
		self::assertSame('stable', $capabilities['proofing_gallery']['integrations']['agent_api_maturity']);
		self::assertFalse($capabilities['proofing_gallery']['integrations']['talk']);
		self::assertSame('POST', $capabilities['client_integration']['proofing_gallery']['context-menu'][1]['method']);
	}

	public function testAgentOcsContractContainsNoPermanentDeleteOperation(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$agentRoutes = array_values(array_filter($routes['ocs'], static fn (array $route): bool => str_starts_with($route['name'], 'Agent#')));

		self::assertNotEmpty($agentRoutes);
		self::assertSame([], array_values(array_filter($agentRoutes, static fn (array $route): bool => $route['verb'] === 'DELETE' && !str_contains($route['url'], '/publish') && !str_contains($route['url'], '/managers/'))));
	}

}
