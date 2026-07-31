<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\NotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class NotificationController extends Controller {
	public function __construct(
		IRequest $request,
		private NotificationService $notifications,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{galleryId}/notification-subscriptions')]
	public function index(int $galleryId): DataResponse {
		try {
			return new DataResponse(['items' => $this->notifications->list($this->userId(), $galleryId)]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param list<string> $eventTypes */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{galleryId}/notification-subscriptions')]
	public function save(int $galleryId, string $recipientUid, array $eventTypes, string $frequency = 'daily', string $locale = 'auto'): DataResponse {
		try {
			return new DataResponse($this->notifications->save($this->userId(), $galleryId, $recipientUid, $eventTypes, $frequency, $locale));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{galleryId}/notification-subscriptions/{id}')]
	public function delete(int $galleryId, int $id): DataResponse {
		try {
			$this->notifications->delete($this->userId(), $galleryId, $id);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Subscription or gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/notifications/unsubscribe/{token}')]
	public function unsubscribe(string $token): DataDisplayResponse {
		$changed = $this->notifications->unsubscribe($token);
		$body = $changed ? 'Gallery notifications have been disabled.' : 'This unsubscribe link is invalid or already used.';
		return new DataDisplayResponse($body, $changed ? Http::STATUS_OK : Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
