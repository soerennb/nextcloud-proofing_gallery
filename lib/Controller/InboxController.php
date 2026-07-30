<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class InboxController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private UploadService $uploads,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{galleryId}/inbox')]
	public function index(int $galleryId): DataResponse {
		try {
			return new DataResponse($this->uploads->listForGallery(
				$this->galleries->view($this->userId(), $galleryId),
			));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{galleryId}/inbox/{uploadId}/accept')]
	public function accept(int $galleryId, string $uploadId): DataResponse {
		return $this->moderate($galleryId, $uploadId, true);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{galleryId}/inbox/{uploadId}')]
	public function reject(int $galleryId, string $uploadId): DataResponse {
		return $this->moderate($galleryId, $uploadId, false);
	}

	private function moderate(int $galleryId, string $uploadId, bool $accept): DataResponse {
		try {
			$this->uploads->moderate(
				$this->galleries->get($this->userId(), $galleryId),
				$uploadId,
				$accept,
			);
			return new DataResponse([]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
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
