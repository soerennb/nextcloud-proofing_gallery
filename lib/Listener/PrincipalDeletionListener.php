<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\Service\PrivacyService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

/** @implements IEventListener<UserDeletedEvent|GroupDeletedEvent> */
final class PrincipalDeletionListener implements IEventListener {
	public function __construct(private PrivacyService $privacy) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) $this->privacy->principalDeleted('user', $event->getUid());
		if ($event instanceof GroupDeletedEvent) $this->privacy->principalDeleted('group', $event->getGroup()->getGID());
	}
}
