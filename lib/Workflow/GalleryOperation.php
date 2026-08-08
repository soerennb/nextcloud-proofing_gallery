<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Workflow;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCA\ProofingGallery\Service\AgentMutationService;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IOperation;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;

final class GalleryOperation implements IOperation, ISpecificOperation {
	private const OPERATIONS = ['archive', 'restore', 'complete', 'publish', 'revoke'];
	/** @var array<string, true> */
	private static array $active = [];

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urls,
		private GalleryMapper $galleries,
		private AgentMutationService $mutations,
		private LoggerInterface $logger,
	) {
	}

	public function getEntityId(): string { return GalleryEntity::class; }
	public function getDisplayName(): string { return $this->l10n->t('Update customer gallery state'); }
	public function getDescription(): string { return $this->l10n->t('Publish, revoke, complete, archive, or restore a customer gallery.'); }
	public function getIcon(): string { return $this->urls->imagePath('proofing_gallery', 'app.svg'); }
	public function isAvailableForScope(int $scope): bool { return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true); }

	/** @param array<int, array<string, mixed>> $checks */
	public function validateOperation(string $name, array $checks, string $operation): void {
		if (!in_array($operation, self::OPERATIONS, true)) throw new \UnexpectedValueException($this->l10n->t('Choose a supported gallery operation.'));
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		if ($eventName !== GalleryIntegrationEvent::class || !$event instanceof GalleryIntegrationEvent || $event->galleryId === null) return;
		$actorUid = is_string($event->payload['actorUid'] ?? null) ? trim($event->payload['actorUid']) : '';
		if ($actorUid === '') return;
		foreach ($ruleMatcher->getFlows(false) as $flow) {
			$operation = (string)($flow['operation'] ?? '');
			if (!in_array($operation, self::OPERATIONS, true)) continue;
			$key = $event->galleryId . ':' . $operation;
			if (isset(self::$active[$key])) continue;
			try {
				self::$active[$key] = true;
				$gallery = $this->galleries->find($event->galleryId);
				if (($operation === 'archive' && $gallery->getStatus() === 'archived')
					|| ($operation === 'restore' && $gallery->getStatus() !== 'archived')
					|| ($operation === 'complete' && $gallery->getWorkflowState() === 'completed')
					|| ($operation === 'publish' && $gallery->getStatus() === 'published')
					|| ($operation === 'revoke' && $gallery->getStatus() !== 'published')) continue;
				$requestId = 'flow-' . hash('sha256', (string)($event->payload['eventId'] ?? '') . ':' . (string)($flow['id'] ?? '') . ':' . $operation);
				match ($operation) {
					'archive' => $this->mutations->archive($actorUid, $event->galleryId, $gallery->getRevision(), $requestId),
					'restore' => $this->mutations->restore($actorUid, $event->galleryId, $gallery->getRevision(), $requestId),
					'complete' => $this->mutations->complete($actorUid, $event->galleryId, $gallery->getRevision(), $requestId),
					'publish' => $this->mutations->publish($actorUid, $event->galleryId, $gallery->getRevision(), $requestId, null, null),
					'revoke' => $this->mutations->revoke($actorUid, $event->galleryId, $gallery->getRevision(), $requestId),
				};
			} catch (\Throwable $exception) {
				$this->logger->warning('Proofing Gallery Flow operation failed', ['exception' => $exception, 'galleryId' => $event->galleryId, 'operation' => $operation]);
			} finally {
				unset(self::$active[$key]);
			}
		}
	}
}
