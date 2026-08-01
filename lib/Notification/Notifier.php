<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Notification;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\GalleryAccessService;
use OCA\ProofingGallery\Service\NativeNotificationService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IDismissableNotifier;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements IDismissableNotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
		private GalleryMapper $galleries,
		private GalleryAccessService $access,
		private NativeNotificationService $native,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Proofing Gallery');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getObjectType() !== 'proofing_gallery_attention') {
			throw new UnknownNotificationException();
		}
		$state = $this->native->state((int)$notification->getObjectId(), $notification->getUser());
		if ($state === null) throw new AlreadyProcessedException();
		try {
			$gallery = $this->galleries->find((int)$state['gallery_id']);
			$this->access->view($notification->getUser(), (int)$state['gallery_id']);
			if ($gallery->getStatus() === 'archived') throw new \RuntimeException('Archived gallery');
		} catch (\Throwable) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$count = max(1, (int)$state['event_count']);
		$category = (string)$state['category'];
		$tab = match ($category) {
			'comment', 'selection', 'upload' => 'feedback',
			'lifecycle', 'revoked' => 'delivery',
			default => 'overview',
		};
		$link = $this->url->linkToRouteAbsolute('proofing_gallery.page.index') . '#gallery/' . $gallery->getId() . '/' . $tab;
		$subject = match ($category) {
			'comment' => $l->n('%n new comment in {gallery}', '%n new comments in {gallery}', $count),
			'selection' => $l->n('%n new client selection in {gallery}', '%n new client selections in {gallery}', $count),
			'upload' => $l->n('%n new upload in {gallery}', '%n new uploads in {gallery}', $count),
			'manager' => $l->t('You can now manage {gallery}'),
			'lifecycle' => $l->t('Public access to {gallery} will end soon'),
			'revoked' => $l->t('Public access to {gallery} was revoked'),
			default => throw new UnknownNotificationException(),
		};
		return $notification
			->setRichSubject($subject, ['gallery' => [
				'type' => 'highlight', 'id' => (string)$gallery->getId(), 'name' => $gallery->getTitle(), 'link' => $link,
			]])
			->setLink($link)
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
	}

	public function dismissNotification(INotification $notification): void {
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getObjectType() !== 'proofing_gallery_attention') {
			throw new UnknownNotificationException();
		}
		$this->native->dismiss((int)$notification->getObjectId(), $notification->getUser());
	}
}
