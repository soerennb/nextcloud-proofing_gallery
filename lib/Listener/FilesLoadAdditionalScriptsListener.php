<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\ProofingGallery\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
final class FilesLoadAdditionalScriptsListener implements IEventListener {
	public function __construct(private IConfig $config) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) return;
		$major = (int)explode('.', $this->config->getSystemValueString('version', '0'))[0];
		Util::addScript(Application::APP_ID, $major >= 33 ? 'proofing_gallery-files-modern' : 'proofing_gallery-files-legacy');
	}
}
