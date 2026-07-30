<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Activity;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;

final class Provider implements IProvider {
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID || $event->getSubject() !== 'gallery_event') {
			throw new UnknownActivityException();
		}
		$parameters = $event->getSubjectParameters();
		return $event->setParsedSubject((string)($parameters['message'] ?? 'New gallery activity'));
	}
}
