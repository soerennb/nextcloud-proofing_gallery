<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\AppInfo;

use OCA\ProofingGallery\Share\PublicShareTemplateProvider;
use OCA\ProofingGallery\Listener\PrincipalDeletionListener;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Notification\Notifier;
use OCA\ProofingGallery\Listener\MediaIndexCacheListener;
use OCA\ProofingGallery\Listener\FilesLoadAdditionalScriptsListener;
use OCA\ProofingGallery\Listener\GalleryFilesMetadataProvider;
use OCA\ProofingGallery\Service\CollectionAnchorReferences;
use OCA\ProofingGallery\Capabilities;
use OCA\ProofingGallery\Dashboard\GalleryAttentionWidget;
use OCA\ProofingGallery\Reference\GalleryReferenceProvider;
use OCA\ProofingGallery\Search\GallerySearchProvider;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\FileCacheUpdated;
use OCP\Files\Events\NodeAddedToCache;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\FilesMetadata\Event\MetadataBackgroundEvent;
use OCP\FilesMetadata\Event\MetadataLiveEvent;
use OCP\Collaboration\Resources\IProviderManager;
use OCA\ProofingGallery\Collaboration\GalleryResourceProvider;
use OCA\ProofingGallery\ContextChat\GalleryContentProvider;
use OCA\ProofingGallery\ContextChat\GalleryContentSyncListener;
use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCA\ProofingGallery\Listener\RegisterWorkflowIntegrationListener;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCA\ProofingGallery\SetupCheck\BackgroundJobsCheck;
use OCA\ProofingGallery\SetupCheck\RuntimeDependenciesCheck;
use OCA\ProofingGallery\SetupCheck\SchemaReadinessCheck;
use OCA\ProofingGallery\UserMigration\ProofingGalleryMigrator;

final class Application extends App implements IBootstrap {
	public const APP_ID = 'proofing_gallery';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerSearchProvider(GallerySearchProvider::class);
		$context->registerReferenceProvider(GalleryReferenceProvider::class);
		$context->registerDashboardWidget(GalleryAttentionWidget::class);
		$context->registerPublicShareTemplateProvider(PublicShareTemplateProvider::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerSetupCheck(SchemaReadinessCheck::class);
		$context->registerSetupCheck(BackgroundJobsCheck::class);
		$context->registerSetupCheck(RuntimeDependenciesCheck::class);
		$context->registerUserMigrator(ProofingGalleryMigrator::class);
		$context->registerServiceAlias(CollectionAnchorReferences::class, GalleryMapper::class);
		$context->registerEventListener(FileCacheUpdated::class, MediaIndexCacheListener::class);
		$context->registerEventListener(NodeAddedToCache::class, MediaIndexCacheListener::class);
		$context->registerEventListener(NodeRemovedFromCache::class, MediaIndexCacheListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesLoadAdditionalScriptsListener::class);
		$context->registerEventListener(MetadataLiveEvent::class, GalleryFilesMetadataProvider::class);
		$context->registerEventListener(MetadataBackgroundEvent::class, GalleryFilesMetadataProvider::class);
		$context->registerEventListener(RegisterEntitiesEvent::class, RegisterWorkflowIntegrationListener::class);
		$context->registerEventListener(RegisterOperationsEvent::class, RegisterWorkflowIntegrationListener::class);
		$context->registerEventListener(UserDeletedEvent::class, PrincipalDeletionListener::class);
		$context->registerEventListener(GroupDeletedEvent::class, PrincipalDeletionListener::class);
		if (interface_exists('OCP\\ContextChat\\IContentProvider')) {
			$context->registerEventListener('OCP\\ContextChat\\Events\\ContentProviderRegisterEvent', GalleryContentProvider::class);
			$context->registerEventListener(GalleryIntegrationEvent::class, GalleryContentSyncListener::class);
		}
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(static function (IProviderManager $providers): void {
			$providers->registerResourceProvider(GalleryResourceProvider::class);
		});
	}
}
