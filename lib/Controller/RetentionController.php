<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\RetentionHandoffService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class RetentionController extends Controller {
	public function __construct(IRequest $request, private GalleryService $galleries, private RetentionHandoffService $retention, private IUserSession $session) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/retention')]
	public function assign(int $id): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			if ($gallery->getStatus() !== 'archived') throw new \InvalidArgumentException('Archive the gallery before retention handoff');
			return new DataResponse($this->retention->assign($gallery, $this->userId()));
		} catch (DoesNotExistException|AuthorizationException) { return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND); }
		catch (\InvalidArgumentException|\RuntimeException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}/retention')]
	public function remove(int $id): DataResponse {
		try { return new DataResponse($this->retention->remove($this->galleries->get($this->userId(), $id), $this->userId())); }
		catch (DoesNotExistException|AuthorizationException) { return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND); }
		catch (\RuntimeException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
	}

	private function userId(): string {
		return $this->session->getUser()?->getUID() ?? throw new \RuntimeException('Authenticated user required');
	}
}
