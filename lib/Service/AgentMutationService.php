<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\IURLGenerator;

final class AgentMutationService {
	public function __construct(
		private GalleryService $galleries,
		private IntegrationReadService $read,
		private PresetService $presets,
		private PublicShareService $shares,
		private ManagerService $managers,
		private AgentIdempotencyService $idempotency,
		private IntegrationEventService $events,
		private ReviewWorkflowService $reviews,
		private IURLGenerator $urls,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{data: array<string, mixed>, replayed: bool}
	 */
	public function create(string $userUid, string $requestId, array $payload): array {
		return $this->mutate($userUid, 'gallery.create', $requestId, $payload, function () use ($userUid, $payload): array {
			$title = (string)($payload['title'] ?? '');
			$sourceType = (string)($payload['sourceType'] ?? 'folder');
			$gallery = $this->galleries->create(
				$userUid,
				$title,
				isset($payload['folderId']) ? (int)$payload['folderId'] : null,
				is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
				$sourceType,
				(string)($payload['purpose'] ?? 'custom'),
			);
			$this->events->emit('gallery.created', $gallery->getId(), ['actorUid' => $userUid]);
			return $this->read->galleryById($userUid, (int)$gallery->getId());
		});
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{data: array<string, mixed>, replayed: bool}
	 */
	public function update(string $userUid, int $galleryId, string $requestId, array $payload): array {
		return $this->mutate($userUid, 'gallery.update.' . $galleryId, $requestId, $payload, function () use ($userUid, $galleryId, $payload): array {
			$expectedRevision = $this->revision($payload);
			$this->galleries->update(
				$userUid,
				$galleryId,
				array_key_exists('title', $payload) ? (string)$payload['title'] : null,
				array_key_exists('settings', $payload) && is_array($payload['settings']) ? $payload['settings'] : null,
				$expectedRevision,
			);
			$this->events->emit('gallery.updated', $galleryId, ['actorUid' => $userUid]);
			return $this->read->galleryById($userUid, $galleryId);
		});
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function applyPreset(string $userUid, int $galleryId, int $presetId, int $expectedRevision, string $requestId): array {
		$payload = compact('galleryId', 'presetId', 'expectedRevision');
		return $this->mutate($userUid, 'gallery.preset.' . $galleryId, $requestId, $payload, function () use ($userUid, $galleryId, $presetId, $expectedRevision): array {
			$this->presets->apply($userUid, $presetId, $galleryId, $expectedRevision);
			$this->events->emit('gallery.updated', $galleryId, ['actorUid' => $userUid, 'presetId' => $presetId]);
			return $this->read->galleryById($userUid, $galleryId);
		});
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function publish(string $userUid, int $galleryId, int $expectedRevision, string $requestId, ?string $expiresAt, ?string $downloadScope): array {
		$payload = compact('galleryId', 'expectedRevision', 'expiresAt', 'downloadScope');
		return $this->mutate($userUid, 'gallery.publish.' . $galleryId, $requestId, $payload, function () use ($userUid, $galleryId, $expectedRevision, $expiresAt, $downloadScope): array {
			$gallery = $this->galleries->get($userUid, $galleryId);
			$this->assertGalleryRevision($gallery, $expectedRevision);
			if ($downloadScope === null) {
				$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
				$downloadScope = $settings->delivery->downloadScope->value;
			}
			$gallery = $this->shares->publish($gallery, null, $expiresAt, $downloadScope);
			$this->events->emit('gallery.published', $galleryId, ['actorUid' => $userUid]);
			return [
				'gallery' => $this->read->galleryById($userUid, $galleryId),
				'url' => $this->urls->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $gallery->getShareToken()]),
			];
		});
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function revoke(string $userUid, int $galleryId, int $expectedRevision, string $requestId): array {
		return $this->stateMutation($userUid, $galleryId, $expectedRevision, $requestId, 'gallery.revoke', 'gallery.unpublished', fn (Gallery $gallery): Gallery => $this->shares->revoke($gallery));
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function archive(string $userUid, int $galleryId, int $expectedRevision, string $requestId): array {
		return $this->stateMutation($userUid, $galleryId, $expectedRevision, $requestId, 'gallery.archive', 'gallery.archived', fn (): Gallery => $this->galleries->archive($userUid, $galleryId));
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function restore(string $userUid, int $galleryId, int $expectedRevision, string $requestId): array {
		return $this->stateMutation($userUid, $galleryId, $expectedRevision, $requestId, 'gallery.restore', 'gallery.restored', fn (): Gallery => $this->galleries->restore($userUid, $galleryId));
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function complete(string $userUid, int $galleryId, int $expectedRevision, string $requestId): array {
		return $this->stateMutation($userUid, $galleryId, $expectedRevision, $requestId, 'gallery.complete', 'gallery.completed', fn (): Gallery => $this->galleries->complete($userUid, $galleryId));
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function saveManager(string $userUid, int $galleryId, string $requestId, string $type, string $principalId, string $role): array {
		$payload = compact('galleryId', 'type', 'principalId', 'role');
		return $this->mutate($userUid, 'gallery.manager.save.' . $galleryId, $requestId, $payload, function () use ($userUid, $galleryId, $type, $principalId, $role): array {
			$manager = $this->managers->save($userUid, $galleryId, $type, $principalId, $role);
			$this->events->emit('gallery.access_changed', $galleryId, ['actorUid' => $userUid, 'principalType' => $type]);
			return ['id' => (int)$manager->getId(), 'type' => $manager->getPrincipalType(), 'principalId' => $manager->getUserUid(), 'role' => $manager->getRole()];
		});
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function removeManager(string $userUid, int $galleryId, int $managerId, string $requestId): array {
		$payload = compact('galleryId', 'managerId');
		return $this->mutate($userUid, 'gallery.manager.remove.' . $galleryId, $requestId, $payload, function () use ($userUid, $galleryId, $managerId): array {
			$this->managers->remove($userUid, $galleryId, $managerId);
			$this->events->emit('gallery.access_changed', $galleryId, ['actorUid' => $userUid]);
			return ['removed' => true];
		});
	}

	/** @return array{data: array<string, mixed>, replayed: bool} */
	public function transitionReview(string $userUid, int $galleryId, int $linkId, string $action, string $requestId): array {
		$payload = compact('galleryId', 'linkId', 'action');
		return $this->mutate($userUid, 'review.' . $action . '.' . $linkId, $requestId, $payload, fn (): array => $this->reviews->transition($userUid, $galleryId, $linkId, $action));
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param callable(): array<string, mixed> $callback
	 * @return array{data: array<string, mixed>, replayed: bool}
	 */
	private function mutate(string $userUid, string $operation, string $requestId, array $payload, callable $callback): array {
		return $this->idempotency->run($userUid, $operation, $requestId, $payload, $callback);
	}

	/**
	 * @param callable(Gallery): Gallery $callback
	 * @return array{data: array<string, mixed>, replayed: bool}
	 */
	private function stateMutation(string $userUid, int $galleryId, int $expectedRevision, string $requestId, string $operation, string $event, callable $callback): array {
		$payload = compact('galleryId', 'expectedRevision');
		return $this->mutate($userUid, $operation . '.' . $galleryId, $requestId, $payload, function () use ($userUid, $galleryId, $expectedRevision, $callback, $event): array {
			$gallery = $this->galleries->get($userUid, $galleryId);
			$this->assertGalleryRevision($gallery, $expectedRevision);
			$callback($gallery);
			$this->events->emit($event, $galleryId, ['actorUid' => $userUid]);
			return $this->read->galleryById($userUid, $galleryId);
		});
	}

	/** @param array<string, mixed> $payload */
	private function revision(array $payload): int {
		if (!isset($payload['expectedRevision']) || !is_int($payload['expectedRevision'])) throw new \InvalidArgumentException('expectedRevision is required');
		return $payload['expectedRevision'];
	}

	private function assertGalleryRevision(Gallery $gallery, int $expectedRevision): void {
		if ($gallery->getRevision() !== $expectedRevision) throw new GalleryConflictException('The gallery changed before the operation could be applied');
	}
}
