<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AgentRequestConflictException;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCA\ProofingGallery\Exception\GalleryNotReadyException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Service\AgentMutationService;
use OCA\ProofingGallery\Service\IntegrationReadService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;

/**
 * Curated, current-user API for automation and Context Agent tools.
 * It deliberately excludes passwords, guest identities and permanent deletion.
 */
final class AgentController extends OCSController {
	public function __construct(
		IRequest $request,
		private IntegrationReadService $read,
		private AgentMutationService $mutations,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/agent/galleries')]
	public function galleries(string $query = '', string $status = '', string $purpose = '', int $limit = 25, ?string $cursor = null): DataResponse {
		return $this->respond(fn (): array => $this->read->galleries($this->userId(), $query, $status, $purpose, $limit, $cursor));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/agent/galleries/{galleryId}')]
	public function gallery(int $galleryId): DataResponse {
		return $this->respond(fn (): array => $this->read->galleryById($this->userId(), $galleryId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/agent/galleries/{galleryId}/readiness')]
	public function readiness(int $galleryId): DataResponse {
		return $this->respond(fn (): array => $this->read->readiness($this->userId(), $galleryId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/agent/galleries/{galleryId}/feedback')]
	public function feedback(int $galleryId): DataResponse {
		return $this->respond(fn (): array => $this->read->feedback($this->userId(), $galleryId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/agent/galleries/{galleryId}/reviews')]
	public function reviews(int $galleryId): DataResponse {
		return $this->respond(fn (): array => $this->read->reviews($this->userId(), $galleryId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/agent/galleries/{galleryId}/media')]
	public function media(int $galleryId, string $query = '', int $minRating = 0, int $limit = 50, ?string $cursor = null): DataResponse {
		return $this->respond(fn (): array => $this->read->media($this->userId(), $galleryId, $query, $minRating, $limit, $cursor));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/agent/presets')]
	public function presets(): DataResponse {
		return $this->respond(fn (): array => ['items' => $this->read->presets($this->userId())]);
	}

	/** @param array<string, mixed> $gallery */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/agent/galleries')]
	public function create(string $requestId, array $gallery): DataResponse {
		return $this->respond(fn (): array => $this->mutations->create($this->userId(), $requestId, $gallery), Http::STATUS_CREATED);
	}

	/** @param array<string, mixed> $changes */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/agent/galleries/{galleryId}')]
	public function update(int $galleryId, string $requestId, array $changes): DataResponse {
		return $this->respond(fn (): array => $this->mutations->update($this->userId(), $galleryId, $requestId, $changes));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/agent/galleries/{galleryId}/preset')]
	public function applyPreset(int $galleryId, int $presetId, int $expectedRevision, string $requestId): DataResponse {
		return $this->respond(fn (): array => $this->mutations->applyPreset($this->userId(), $galleryId, $presetId, $expectedRevision, $requestId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/agent/galleries/{galleryId}/publish')]
	public function publish(int $galleryId, int $expectedRevision, string $requestId, ?string $expiresAt = null, ?string $downloadScope = null): DataResponse {
		return $this->respond(fn (): array => $this->mutations->publish($this->userId(), $galleryId, $expectedRevision, $requestId, $expiresAt, $downloadScope));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/agent/galleries/{galleryId}/publish')]
	public function revoke(int $galleryId, int $expectedRevision, string $requestId): DataResponse {
		return $this->respond(fn (): array => $this->mutations->revoke($this->userId(), $galleryId, $expectedRevision, $requestId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/agent/galleries/{galleryId}/archive')]
	public function archive(int $galleryId, int $expectedRevision, string $requestId): DataResponse {
		return $this->respond(fn (): array => $this->mutations->archive($this->userId(), $galleryId, $expectedRevision, $requestId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/agent/galleries/{galleryId}/restore')]
	public function restore(int $galleryId, int $expectedRevision, string $requestId): DataResponse {
		return $this->respond(fn (): array => $this->mutations->restore($this->userId(), $galleryId, $expectedRevision, $requestId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/agent/galleries/{galleryId}/complete')]
	public function complete(int $galleryId, int $expectedRevision, string $requestId): DataResponse {
		return $this->respond(fn (): array => $this->mutations->complete($this->userId(), $galleryId, $expectedRevision, $requestId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/agent/galleries/{galleryId}/managers')]
	public function saveManager(int $galleryId, string $requestId, string $type, string $principalId, string $role): DataResponse {
		return $this->respond(fn (): array => $this->mutations->saveManager($this->userId(), $galleryId, $requestId, $type, $principalId, $role));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/agent/galleries/{galleryId}/managers/{managerId}')]
	public function removeManager(int $galleryId, int $managerId, string $requestId): DataResponse {
		return $this->respond(fn (): array => $this->mutations->removeManager($this->userId(), $galleryId, $managerId, $requestId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/agent/galleries/{galleryId}/public-links/{linkId}/review/{transition}')]
	public function transitionReview(int $galleryId, int $linkId, string $transition, string $requestId): DataResponse {
		return $this->respond(fn (): array => $this->mutations->transitionReview($this->userId(), $galleryId, $linkId, $transition, $requestId));
	}

	/**
	 * @param callable(): array<string, mixed> $callback
	 * @param Http::STATUS_OK|Http::STATUS_CREATED $successStatus
	 */
	private function respond(callable $callback, int $successStatus = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($callback(), $successStatus);
		} catch (DoesNotExistException|ShareNotFound|AuthorizationException) {
			return new DataResponse(['code' => 'not_found', 'message' => 'Gallery or resource not found'], Http::STATUS_NOT_FOUND);
		} catch (AgentRequestConflictException|GalleryConflictException $exception) {
			return new DataResponse(['code' => 'conflict', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (GalleryNotReadyException $exception) {
			return new DataResponse(['code' => 'gallery_not_ready', 'message' => $exception->getMessage(), ...$exception->report], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['code' => 'invalid_request', 'message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
