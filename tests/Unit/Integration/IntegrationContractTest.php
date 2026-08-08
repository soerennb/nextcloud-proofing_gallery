<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Integration;

use OCA\ProofingGallery\Capabilities;
use OCA\ProofingGallery\Service\IntegrationEventService;
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

	public function testUserMigrationManifestExcludesNonPortableAndSensitiveState(): void {
		$source = file_get_contents(__DIR__ . '/../../../lib/UserMigration/ProofingGalleryMigrator.php');
		self::assertIsString($source);
		self::assertStringContainsString("'sourcePath'", $source);
		self::assertStringContainsString('GalleryStatus::Draft->value', file_get_contents(__DIR__ . '/../../../lib/Service/GalleryService.php'));
		self::assertStringNotContainsString("'shareToken' =>", strstr($source, 'private function exportGalleries'));
		self::assertStringNotContainsString('GuestMapper', $source);
		self::assertStringNotContainsString('PublicLinkMapper', $source);
		self::assertStringContainsString("['lifecycle']['enabled'] = false", $source);
	}

	public function testTalkRoomsArePrivateAndExplicitlyRemovable(): void {
		$source = file_get_contents(__DIR__ . '/../../../lib/Service/ReviewIntegrationService.php');
		self::assertIsString($source);
		self::assertStringContainsString('setPublic(false)', $source);
		self::assertStringContainsString('deleteConversation($conversationId)', $source);
		self::assertStringContainsString('deleteConversation($conversation->getId())', $source);
		self::assertStringNotContainsString('getShareToken()', $source);
		$privacy = file_get_contents(__DIR__ . '/../../../lib/Service/PrivacyService.php');
		self::assertIsString($privacy);
		self::assertStringContainsString('$this->deleteTalkRooms($galleryId)', $privacy);
	}

	public function testAutomationPayloadSanitizerRemovesSecretsRecursively(): void {
		$service = (new \ReflectionClass(IntegrationEventService::class))->newInstanceWithoutConstructor();
		$sanitize = new \ReflectionMethod(IntegrationEventService::class, 'sanitize');
		$result = $sanitize->invoke($service, [
			'state' => '<b>approved</b>', 'token' => 'secret',
			'nested' => ['email' => 'client@example.test', 'round' => 2, 'shareToken' => 'secret'],
		]);

		self::assertSame(['state' => 'approved', 'nested' => ['round' => 2]], $result);
	}

	public function testAgentOcsContractContainsNoPermanentDeleteOperation(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$agentRoutes = array_values(array_filter($routes['ocs'], static fn (array $route): bool => str_starts_with($route['name'], 'Agent#')));

		self::assertNotEmpty($agentRoutes);
		self::assertSame([], array_values(array_filter($agentRoutes, static fn (array $route): bool => $route['verb'] === 'DELETE' && !str_contains($route['url'], '/publish') && !str_contains($route['url'], '/managers/'))));
	}

	public function testContextAgentModuleDoesNotExposeSecretsOrPermanentDeletion(): void {
		$source = file_get_contents(__DIR__ . '/../../../integrations/context_agent/proofing_gallery.py');
		self::assertIsString($source);
		self::assertStringNotContainsString('password:', $source);
		self::assertStringNotContainsString('delete_customer_gallery', $source);
		self::assertStringContainsString('@safe_tool', $source);
		self::assertStringContainsString('@dangerous_tool', $source);
		self::assertStringContainsString('decide_gallery_review', $source);
	}

	public function testFilesMetadataAndContextChatKeepPrivacyBoundary(): void {
		$metadata = file_get_contents(__DIR__ . '/../../../lib/Listener/GalleryFilesMetadataProvider.php');
		$context = file_get_contents(__DIR__ . '/../../../lib/ContextChat/GalleryContentSyncService.php');
		self::assertIsString($metadata);
		self::assertIsString($context);
		self::assertStringContainsString("setBool(self::SOURCE_KEY", $metadata);
		self::assertStringNotContainsString('getTitle()', $metadata);
		self::assertStringNotContainsString('getShareToken()', $metadata);
		self::assertStringNotContainsString('getShareToken()', $context);
		self::assertStringNotContainsString('guest', strtolower($context));
		self::assertStringNotContainsString('comment', strtolower($context));
	}

	public function testFlowRunsAsTheEventActorInsteadOfTheGalleryOwner(): void {
		$source = file_get_contents(__DIR__ . '/../../../lib/Workflow/GalleryOperation.php');
		self::assertIsString($source);
		self::assertStringContainsString("event->payload['actorUid']", $source);
		self::assertStringNotContainsString('mutations->archive($gallery->getOwnerUid()', $source);
		self::assertStringNotContainsString('mutations->publish($gallery->getOwnerUid()', $source);
	}
}
