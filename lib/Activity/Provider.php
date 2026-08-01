<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Activity;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

final class Provider implements IProvider {
	public function __construct(private IFactory $l10nFactory, private IURLGenerator $url) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID || $event->getSubject() !== 'gallery_event') {
			throw new UnknownActivityException();
		}
		$parameters = $event->getSubjectParameters();
		$l = $this->l10nFactory->get(Application::APP_ID, (string)$language);
		$type = (string)($parameters['eventType'] ?? '');
		$title = (string)($parameters['galleryTitle'] ?? $event->getObjectName());
		$link = $this->url->linkToRouteAbsolute('proofing_gallery.page.index') . '#gallery/' . $event->getObjectId()
			. (in_array($type, ['comment.created', 'selection.created', 'upload.received'], true) ? '/feedback' : '/activity');
		$subject = match ($type) {
			'comment.created' => $l->t('A client added a comment in {gallery}'),
			'comment.updated' => $l->t('A client edited a comment in {gallery}'),
			'selection.created' => $l->t('A client submitted a selection in {gallery}'),
			'like.changed' => $l->t('A client changed a like in {gallery}'),
			'color.changed' => $l->t('A client changed a color status in {gallery}'),
			'upload.received' => $l->t('A client uploaded a file to {gallery}'),
			'upload.accepted' => $l->t('An upload was accepted in {gallery}'),
			'upload.rejected' => $l->t('An upload was rejected in {gallery}'),
			default => $l->t('New activity in {gallery}'),
		};
		return $event
			->setRichSubject($subject, ['gallery' => [
				'type' => 'highlight', 'id' => (string)$event->getObjectId(), 'name' => $title, 'link' => $link,
			]])
			->setLink($link)
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
	}
}
