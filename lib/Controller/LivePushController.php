<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\LivePushService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class LivePushController extends Controller {
	public function __construct(IRequest $request, private GalleryService $galleries, private LivePushService $livePush, private IUserSession $session) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/live-push')]
	public function index(int $id): DataResponse {
		return $this->respond(fn () => $this->livePush->overview($this->galleries->get($this->userId(), $id)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/live-push')]
	public function create(int $id, string $label, string $path = ''): DataResponse {
		return $this->respond(fn () => $this->livePush->create($this->galleries->get($this->userId(), $id), $this->userId(), $label, $path), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/live-push/{credentialId}/rotate')]
	public function rotate(int $id, int $credentialId): DataResponse {
		return $this->respond(fn () => $this->livePush->rotate($this->galleries->get($this->userId(), $id), $credentialId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}/live-push/{credentialId}')]
	public function revoke(int $id, int $credentialId): DataResponse {
		return $this->respond(function () use ($id, $credentialId): array {
			$this->livePush->revoke($this->galleries->get($this->userId(), $id), $credentialId);
			return [];
		}, Http::STATUS_NO_CONTENT);
	}

	/** @param Http::STATUS_OK|Http::STATUS_CREATED|Http::STATUS_NO_CONTENT $status */
	private function respond(callable $callback, int $status = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($callback(), $status);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
