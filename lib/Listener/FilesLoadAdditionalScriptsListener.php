<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\ProofingGallery\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
final class FilesLoadAdditionalScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) return;
		Util::addScript(Application::APP_ID, 'proofing_gallery-files-modern');
	}
}
