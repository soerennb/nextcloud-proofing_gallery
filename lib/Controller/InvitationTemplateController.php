<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\InvitationTemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class InvitationTemplateController extends Controller {
	public function __construct(
		IRequest $request,
		private InvitationTemplateService $templates,
		private GalleryService $galleries,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/invitation-templates')]
	public function index(): DataResponse {
		return new DataResponse(['items' => $this->templates->list($this->userId())]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/invitation-templates')]
	public function create(string $name, string $body): DataResponse {
		try {
			return new DataResponse($this->templates->create($this->userId(), $name, $body), Http::STATUS_CREATED);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/invitation-templates/{id}')]
	public function update(int $id, ?string $name = null, ?string $body = null): DataResponse {
		try {
			return new DataResponse($this->templates->update($this->userId(), $id, $name, $body));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Invitation template not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/invitation-templates/{id}')]
	public function delete(int $id): DataResponse {
		try {
			$this->templates->delete($this->userId(), $id);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Invitation template not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/invitation-templates/{id}/render/{galleryId}')]
	public function render(int $id, int $galleryId): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->get($userId, $galleryId);
			return new DataResponse(['body' => $this->templates->render($userId, $id, $gallery)]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Invitation template or gallery not found'], Http::STATUS_NOT_FOUND);
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
