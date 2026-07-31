<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\AppInfo;

use OCA\ProofingGallery\Share\PublicShareTemplateProvider;
use OCA\ProofingGallery\BackgroundJob\PurgeGuestsJob;
use OCA\ProofingGallery\BackgroundJob\CleanupGalleryDataJob;
use OCA\ProofingGallery\BackgroundJob\SendNotificationDigestsJob;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\CollectionAnchorReferences;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
	public const APP_ID = 'proofing_gallery';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerPublicShareTemplateProvider(PublicShareTemplateProvider::class);
		$context->registerServiceAlias(CollectionAnchorReferences::class, GalleryMapper::class);
	}

	public function boot(IBootContext $context): void {
		$context->getServerContainer()->get(IJobList::class)->add(PurgeGuestsJob::class);
		$context->getServerContainer()->get(IJobList::class)->add(CleanupGalleryDataJob::class);
		$context->getServerContainer()->get(IJobList::class)->add(SendNotificationDigestsJob::class);
	}
}
