<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\GalleryFaviconService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @implements IEventListener<BeforeTemplateRenderedEvent> */
final class GalleryFaviconListener implements IEventListener {
	public function __construct(private GalleryFaviconService $favicons, private IInitialState $state) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent) return;
		$response = $event->getResponse();
		if ($response->getApp() !== Application::APP_ID || !in_array($response->getTemplateName(), ['index', 'public', 'public-unavailable'], true)) return;
		$links = $this->favicons->links();
		foreach ($links as $link) Util::addHeader('link', $link);
		$this->state->provideInitialState('favicons', $links);
		Util::addScript(Application::APP_ID, 'proofing_gallery-favicon');
	}
}
