<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCA\ProofingGallery\Service\CullingService;
use OCA\ProofingGallery\Service\CullingXmpService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\MediaIndexService;
use OCA\ProofingGallery\Service\CapabilityPolicyService;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class CullingController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private MediaIndexService $mediaIndex,
		private CullingService $culling,
		private CullingXmpService $cullingXmp,
		private IUserSession $userSession,
		private CapabilityPolicyService $capabilities,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/media/index')]
	public function rebuildMediaIndex(int $id): DataResponse {
		try {
			$this->capabilities->assertFeature('ownerCulling');
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse($this->mediaIndex->rebuild($gallery));
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery or folder not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/indexed-media')]
	public function indexedMedia(int $id, int $limit = 60, ?string $cursor = null, string $path = '', string $search = '', string $sortBy = 'name', string $sortDirection = 'asc'): DataResponse {
		try {
			$this->capabilities->assertFeature('ownerCulling');
			$gallery = $this->galleries->view($this->userId(), $id);
			return new DataResponse($this->mediaIndex->page($gallery, $limit, $cursor, $path, $search, $sortBy, $sortDirection));
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery or folder not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param list<array<string, mixed>> $items */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/media/cull')]
	public function updateCulling(int $id, array $items): DataResponse {
		try {
			$this->capabilities->assertFeature('ownerCulling');
			$userId = $this->userId();
			$gallery = $this->galleries->get($userId, $id);
			return new DataResponse(['items' => $this->culling->updateBatch($userId, $gallery, $items)]);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (MetadataConflictException $exception) {
			return new DataResponse(['code' => 'revision_conflict', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found or not writable'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param list<int> $fileIds */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/media/cull')]
	public function culling(int $id, array $fileIds = []): DataResponse {
		try {
			$this->capabilities->assertFeature('ownerCulling');
			$gallery = $this->galleries->view($this->userId(), $id);
			if (count($fileIds) > 200) throw new InvalidArgumentException('Request at most 200 media items');
			return new DataResponse(['items' => array_values($this->culling->forFiles($gallery->getOwnerUid(), $fileIds))]);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * @param list<int> $fileIds
	 * @param array<string, string> $fieldChoices
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/culling/xmp')]
	public function synchronizeCullingXmp(
		int $id,
		string $mode = 'report',
		bool $dryRun = true,
		array $fileIds = [],
		int $limit = 200,
		int $offset = 0,
		array $fieldChoices = [],
	): DataResponse {
		try {
			$this->capabilities->assertFeature('ownerCulling');
			$userId = $this->userId();
			$gallery = $this->galleries->get($userId, $id);
			return new DataResponse($this->cullingXmp->synchronize($userId, $gallery, $mode, $dryRun, $fileIds, $limit, $offset, $fieldChoices));
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery or media not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
