<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;

final class InvitationService {
	public function __construct(
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IUserManager $users,
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
		$ownerName = $this->users->getDisplayName($gallery->getOwnerUid()) ?? $gallery->getOwnerUid();
		$template = $this->mailer->createEMailTemplate('proofing_gallery.invitation', [
			'galleryId' => $gallery->getId(),
		]);
		$template->setSubject($ownerName . ' shared “' . $gallery->getTitle() . '” with you');
		$template->addHeader();
		$template->addHeading($gallery->getTitle());
		$template->addBodyText($message !== '' ? $message : $ownerName . ' prepared a private gallery for you.');
		$template->addBodyButton('Open gallery', $url);
		$template->addFooter('This invitation was sent from a self-hosted Nextcloud instance.');

		$mail = $this->mailer->createMessage();
		$mail->setTo([$recipient])
			->useTemplate($template);
		$failed = $this->mailer->send($mail);
		if ($failed !== []) {
			throw new \RuntimeException('The invitation could not be delivered');
		}
	}
}
