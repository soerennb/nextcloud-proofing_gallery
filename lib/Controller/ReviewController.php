<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\ReviewConflictException;
use OCA\ProofingGallery\Service\ReviewWorkflowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class ReviewController extends Controller {
	public function __construct(
		IRequest $request,
		private ReviewWorkflowService $reviews,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{galleryId}/reviews')]
	public function overview(int $galleryId): DataResponse {
		try {
			return new DataResponse($this->reviews->overview($this->userId(), $galleryId));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{galleryId}/public-links/{linkId}/review/{transition}')]
	public function transition(int $galleryId, int $linkId, string $transition): DataResponse {
		try {
			return new DataResponse($this->reviews->transition($this->userId(), $galleryId, $linkId, $transition));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (ReviewConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new AuthorizationException('Authentication required');
	}
}
