<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\PresetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class PresetController extends Controller {
	public function __construct(
		IRequest $request,
		private PresetService $presets,
		private GalleryService $galleries,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/presets')]
	public function index(): DataResponse {
		return new DataResponse(['items' => $this->presets->list($this->userId())]);
	}

	/** @param array<string, mixed> $settings */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/presets')]
	public function create(string $name, array $settings): DataResponse {
		try {
			return new DataResponse($this->presets->create($this->userId(), $name, $settings), Http::STATUS_CREATED);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param array<string, mixed>|null $settings */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/presets/{id}')]
	public function update(int $id, ?string $name = null, ?array $settings = null): DataResponse {
		try {
			return new DataResponse($this->presets->update($this->userId(), $id, $name, $settings));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Preset not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/presets/{id}')]
	public function delete(int $id): DataResponse {
		try {
			$this->presets->delete($this->userId(), $id);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Preset not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/presets/{id}/apply/{galleryId}')]
	public function apply(int $id, int $galleryId): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present($userId, $this->presets->apply($userId, $id, $galleryId)));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Preset or gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
