<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\ContextChat;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\ContextChat\Events\ContentProviderRegisterEvent;
use OCP\ContextChat\IContentProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use OCP\BackgroundJob\IJobList;
use OCA\ProofingGallery\BackgroundJob\ImportGalleryContextContentJob;

/** @template-implements IEventListener<ContentProviderRegisterEvent> */
final class GalleryContentProvider implements IContentProvider, IEventListener {
	public function __construct(private IURLGenerator $urls, private IJobList $jobs) {
	}

	public function handle(Event $event): void {
		if ($event instanceof ContentProviderRegisterEvent) {
			$event->registerContentProvider(Application::APP_ID, $this->getId(), self::class);
		}
	}

	public function getId(): string { return GalleryContentSyncService::PROVIDER_ID; }
	public function getAppId(): string { return Application::APP_ID; }
	public function getItemUrl(string $id): string { return ctype_digit($id) ? $this->urls->linkToRouteAbsolute('proofing_gallery.page.index') . '#gallery/' . $id : ''; }
	public function triggerInitialImport(): void { $this->jobs->add(ImportGalleryContextContentJob::class, ['afterId' => 0]); }
}
