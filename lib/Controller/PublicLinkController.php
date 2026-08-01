<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\GuestRatingService;
use OCA\ProofingGallery\Service\PublicLinkManagerService;
use OCA\ProofingGallery\Service\ShareAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class PublicLinkController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private PublicLinkManagerService $publicLinks,
		private ShareAuditService $shareAudit,
		private GuestRatingService $guestRatings,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/guest-ratings')]
	public function guestRatings(int $id): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse($this->guestRatings->aggregate($gallery));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param list<int> $fileIds */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/guest-ratings/promotion-preview')]
	public function guestRatingPromotionPreview(int $id, array $fileIds = []): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse(['items' => $this->guestRatings->promotionPlan($gallery, $fileIds)]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param list<array<string, mixed>> $items */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/guest-ratings/promote')]
	public function promoteGuestRatings(int $id, array $items = []): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse(['items' => $this->guestRatings->promote($this->userId(), $gallery, $items)]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException|MetadataConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/public-links')]
	public function publicLinks(int $id): DataResponse {
		try {
			return new DataResponse($this->publicLinks->list($this->galleries->get($this->userId(), $id)));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param array<string, mixed> $policy */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/public-links')]
	public function createPublicLink(
		int $id,
		string $name,
		array $policy = [],
		string $startPath = '',
		string $viewMode = 'folder',
		int $groupDepth = 0,
		int $minOwnerRating = 0,
		?string $publicLocale = null,
		?string $password = null,
		?string $expiresAt = null,
	): DataResponse {
		try {
			return new DataResponse($this->publicLinks->create(
				$this->galleries->get($this->userId(), $id),
				PublicLinkConfiguration::fromArray(compact(
					'name', 'policy', 'startPath', 'viewMode', 'groupDepth', 'minOwnerRating',
					'publicLocale', 'password', 'expiresAt',
				)),
			), Http::STATUS_CREATED);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param array<string, mixed> $policy */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/public-links/{linkId}')]
	public function updatePublicLink(
		int $id,
		int $linkId,
		string $name,
		array $policy = [],
		string $startPath = '',
		string $viewMode = 'folder',
		int $groupDepth = 0,
		int $minOwnerRating = 0,
		?string $publicLocale = null,
		?string $password = null,
		?string $expiresAt = null,
	): DataResponse {
		try {
			return new DataResponse($this->publicLinks->update(
				$this->galleries->get($this->userId(), $id),
				$linkId,
				PublicLinkConfiguration::fromArray(compact(
					'name', 'policy', 'startPath', 'viewMode', 'groupDepth', 'minOwnerRating',
					'publicLocale', 'password', 'expiresAt',
				)),
			));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/public-links/{linkId}/primary')]
	public function makePublicLinkPrimary(int $id, int $linkId): DataResponse {
		try {
			return new DataResponse($this->publicLinks->makePrimary($this->galleries->get($this->userId(), $id), $linkId));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}/public-links/{linkId}')]
	public function revokePublicLink(int $id, int $linkId): DataResponse {
		try {
			return new DataResponse($this->publicLinks->revoke($this->galleries->get($this->userId(), $id), $linkId, $this->userId()));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/share-audit')]
	public function shareAudit(int $id, int $limit = 100, int $offset = 0): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse(['items' => $this->shareAudit->forGallery($gallery->getId(), $limit, $offset)]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
