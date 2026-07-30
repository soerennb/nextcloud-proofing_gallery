<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\ActivityService;
use OCA\ProofingGallery\Service\GalleryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class ActivityController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private ActivityService $activity,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{galleryId}/activity')]
	public function index(int $galleryId, int $cursor = 0, string $type = ''): DataResponse {
		try {
			return new DataResponse($this->activity->list(
				$this->galleries->view($this->userId(), $galleryId),
				$cursor,
				$type,
			));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
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
