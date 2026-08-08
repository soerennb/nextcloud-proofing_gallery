<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\PrivacyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

final class PrivacyController extends Controller {
	public function __construct(IRequest $request, private GalleryService $galleries, private PrivacyService $privacy, private IUserSession $session) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/privacy')]
	public function preview(int $id): DataResponse {
		try { return new DataResponse($this->privacy->preview($this->galleries->get($this->userId(), $id))); }
		catch (DoesNotExistException|AuthorizationException) { return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND); }
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/privacy/export')]
	public function export(int $id): Response {
		try { return $this->privacy->export($this->galleries->get($this->userId(), $id)); }
		catch (DoesNotExistException|AuthorizationException) { return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND); }
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/privacy/purge')]
	public function schedule(int $id): DataResponse {
		try { return new DataResponse($this->privacy->schedule($this->galleries->get($this->userId(), $id), $this->userId()), Http::STATUS_CREATED); }
		catch (DoesNotExistException|AuthorizationException) { return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND); }
		catch (\InvalidArgumentException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}/privacy/purge/{requestId}')]
	public function cancel(int $id, int $requestId): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$this->privacy->cancel($gallery, $requestId, $this->userId());
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException|AuthorizationException) { return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND); }
		catch (\InvalidArgumentException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
	}

	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
