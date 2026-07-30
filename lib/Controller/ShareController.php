<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\InvitationService;
use OCA\ProofingGallery\Service\PublicShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;

final class ShareController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private PublicShareService $shares,
		private InvitationService $invitations,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/invite')]
	public function invite(int $id, string $recipient, string $message = ''): DataResponse {
		try {
			$this->invitations->send($this->galleries->get($this->userId(), $id), $recipient, $message);
			return new DataResponse([], Http::STATUS_ACCEPTED);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/publish')]
	public function publish(
		int $id,
		?string $password = null,
		?string $expiresAt = null,
		bool $allowDownloads = false,
	): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$gallery = $this->shares->publish($gallery, $password, $expiresAt, $allowDownloads);
			return new DataResponse([
				'gallery' => $this->galleries->present($this->userId(), $gallery),
				'url' => $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', [
					'token' => $gallery->getShareToken(),
				]),
			]);
		} catch (DoesNotExistException|ShareNotFound|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery or share not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}/publish')]
	public function revoke(int $id): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present(
				$userId,
				$this->shares->revoke($this->galleries->get($userId, $id)),
			));
		} catch (DoesNotExistException|ShareNotFound|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery or share not found'], Http::STATUS_NOT_FOUND);
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
