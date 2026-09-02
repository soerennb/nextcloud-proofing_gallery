<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;

final class InvitationService {
	public function __construct(
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IUserManager $users,
		private IFactory $l10nFactory,
	) {
	}

	public function send(Gallery $gallery, string $recipient, string $message = ''): void {
		if ($gallery->getShareToken() === null) {
			throw new InvalidArgumentException('Publish the gallery before sending invitations');
		}
		if (!$this->mailer->validateMailAddress($recipient)) {
			throw new InvalidArgumentException('Recipient email address is invalid');
		}
		$message = trim($message);
		if (mb_strlen($message) > 2000) {
			throw new InvalidArgumentException('Invitation message may contain at most 2000 characters');
		}

		$url = $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', [
			'token' => $gallery->getShareToken(),
		]);
		$this->sendPublicLink($gallery, $recipient, $url, null, $message);
	}

	public function sendPublicLink(Gallery $gallery, string $recipient, string $url, ?string $locale = null, string $message = ''): void {
		if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) throw new InvalidArgumentException('Invitation URL is invalid');
		if (!$this->mailer->validateMailAddress($recipient)) throw new InvalidArgumentException('Recipient email address is invalid');
		$message = trim($message);
		if (mb_strlen($message) > 2000) throw new InvalidArgumentException('Invitation message may contain at most 2000 characters');
		$ownerName = $this->users->getDisplayName($gallery->getOwnerUid()) ?? $gallery->getOwnerUid();
		$settings = \OCA\ProofingGallery\Dto\GallerySettings::fromArray(
			json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR),
		);
		$language = $locale ?? ($settings->publicLocale === 'auto' ? null : $settings->publicLocale);
		$l10n = $this->l10nFactory->get('proofing_gallery', $language);
		$template = $this->mailer->createEMailTemplate('proofing_gallery.invitation', [
			'galleryId' => $gallery->getId(),
		]);
		$template->setSubject($l10n->t('%s shared “%s” with you', [$ownerName, $gallery->getTitle()]));
		$template->addHeader();
		$template->addHeading($gallery->getTitle());
		$template->addBodyText($message !== '' ? $message : $l10n->t('%s prepared a private gallery for you.', [$ownerName]));
		$template->addBodyButton($l10n->t('Open gallery'), $url);
		$template->addFooter($l10n->t('This invitation was sent from a self-hosted Nextcloud instance.'));

		$mail = $this->mailer->createMessage();
		$mail->setTo([$recipient])
			->useTemplate($template);
		$failed = $this->mailer->send($mail);
		if ($failed !== []) {
			throw new \RuntimeException('The invitation could not be delivered');
		}
	}
}
