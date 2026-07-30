<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\ManagerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class ManagerController extends Controller {
	public function __construct(
		IRequest $request,
		private ManagerService $managers,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{galleryId}/managers')]
	public function index(int $galleryId): DataResponse {
		try {
			return new DataResponse(['items' => $this->managers->list($this->userId(), $galleryId)]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{galleryId}/managers')]
	public function save(int $galleryId, string $type, string $principalId, string $role): DataResponse {
		try {
			return new DataResponse(
				$this->managers->save($this->userId(), $galleryId, $type, $principalId, $role),
				Http::STATUS_CREATED,
			);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{galleryId}/managers/{managerId}')]
	public function remove(int $galleryId, int $managerId): DataResponse {
		try {
			$this->managers->remove($this->userId(), $galleryId, $managerId);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery or manager not found'], Http::STATUS_NOT_FOUND);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Authenticated user required');
		}
		return $user->getUID();
	}
}
