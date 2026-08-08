<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Workflow;

use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCA\ProofingGallery\Service\GalleryAccessService;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\GenericEntityEvent;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IRuleMatcher;

final class GalleryEntity implements IEntity {
	private ?GalleryIntegrationEvent $subject = null;

	public function __construct(private IL10N $l10n, private IURLGenerator $urls, private GalleryAccessService $access) {
	}

	public function getName(): string { return $this->l10n->t('Customer gallery'); }
	public function getIcon(): string { return $this->urls->imagePath('proofing_gallery', 'app.svg'); }

	public function getEvents(): array {
		return [new GenericEntityEvent($this->l10n->t('a customer gallery changes'), GalleryIntegrationEvent::class)];
	}

	public function prepareRuleMatcher(IRuleMatcher $ruleMatcher, string $eventName, Event $event): void {
		if ($eventName !== GalleryIntegrationEvent::class || !$event instanceof GalleryIntegrationEvent) throw new \UnexpectedValueException('Unsupported gallery event');
		$this->subject = $event;
		$ruleMatcher->setEntitySubject($this, $event);
	}

	public function isLegitimatedForUserId(string $userId): bool {
		if ($this->subject?->galleryId === null) return false;
		try {
			$this->access->view($userId, $this->subject->galleryId);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}
}
