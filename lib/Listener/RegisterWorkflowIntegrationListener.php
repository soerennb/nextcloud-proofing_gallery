<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Listener;

use OCA\ProofingGallery\Workflow\GalleryEntity;
use OCA\ProofingGallery\Workflow\GalleryOperation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/** @template-implements IEventListener<RegisterEntitiesEvent|RegisterOperationsEvent> */
final class RegisterWorkflowIntegrationListener implements IEventListener {
	public function __construct(private GalleryEntity $entity, private GalleryOperation $operation) {
	}

	public function handle(Event $event): void {
		if ($event instanceof RegisterEntitiesEvent) $event->registerEntity($this->entity);
		if ($event instanceof RegisterOperationsEvent) $event->registerOperation($this->operation);
	}
}
