<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Exception\ProjectCreationException;
use OCA\ProofingGallery\Db\Gallery;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\ProofingGallery\Service\GalleryReadinessService;

final class GalleryController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private GalleryReadinessService $readiness,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/readiness')]
	public function readiness(int $id): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->readiness->evaluate($this->galleries->view($userId, $id), $userId));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}


	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries')]
	public function index(
		int $limit = 25,
		int $offset = 0,
		bool $archived = false,
		string $search = '',
		?string $sourceType = null,
		bool $ownedOnly = false,
	): DataResponse {
		try {
			return new DataResponse($this->galleries->list(
				$this->userId(),
				$limit,
				$offset,
				$archived,
				$search,
				$sourceType,
				$ownedOnly,
			));
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v2/galleries')]
	public function indexV2(
		int $limit = 50,
		?string $cursor = null,
		bool $archived = false,
		string $search = '',
		?string $sourceType = null,
		?string $status = null,
		?string $mode = null,
		?string $purpose = null,
		bool $ownedOnly = false,
		string $sort = 'updated',
	): DataResponse {
		try {
			return new DataResponse($this->galleries->listV2(
				$this->userId(), $limit, $cursor, $archived, $search,
				$sourceType, $status, $mode, $purpose, $ownedOnly, $sort,
			));
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param array<string, mixed> $settings */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries')]
	public function create(string $title, ?int $folderId = null, array $settings = [], string $sourceType = 'folder'): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->create($userId, $title, $folderId, $settings, $sourceType);
			return new DataResponse($this->galleries->present($userId, $gallery), Http::STATUS_CREATED);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $designPreset
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/projects')]
	public function createProject(
		string $title,
		string $purpose = 'delivery',
		string $sourceMode = 'existing',
		?int $folderId = null,
		?int $parentFolderId = null,
		?string $folderName = null,
		array $settings = [],
		array $designPreset = ['mode' => 'inherit'],
		string $deliveryMode = 'standard',
	): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->createProject(
				$userId, $title, $purpose, $sourceMode, $folderId, $parentFolderId, $folderName, $settings, $designPreset, $deliveryMode,
			);
			return new DataResponse($this->galleries->present($userId, $gallery), Http::STATUS_CREATED);
		} catch (ProjectCreationException $exception) {
			return new DataResponse(['code' => 'invalid_project_combination', 'message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}', requirements: ['id' => '\d+'])]
	public function show(int $id): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present($userId, $this->galleries->view($userId, $id)));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param array<string, mixed>|null $settings */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}')]
	public function update(int $id, ?string $title = null, ?array $settings = null, ?int $expectedRevision = null): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present(
				$userId,
				$this->galleries->update($userId, $id, $title, $settings, $expectedRevision),
			));
		} catch (GalleryConflictException $exception) {
			$userId = $this->userId();
			return new DataResponse([
				'code' => 'revision_conflict',
				'message' => $exception->getMessage(),
				'gallery' => $this->galleries->present($userId, $this->galleries->view($userId, $id)),
			], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/source')]
	public function source(int $id, int $folderId): DataResponse {
		try {
			$userId = $this->userId();
			$result = $this->galleries->rebindSource($userId, $id, $folderId);
			return new DataResponse([
				...$this->galleries->present($userId, $result->gallery),
				'sourceRebind' => $result->report(),
			]);
		} catch (GalleryConflictException $exception) {
			return new DataResponse(['code' => 'revision_conflict', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}')]
	public function archive(int $id): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present($userId, $this->galleries->archive($userId, $id)));
		} catch (GalleryConflictException $exception) {
			return new DataResponse(['code' => 'revision_conflict', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/restore')]
	public function restore(int $id): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present($userId, $this->galleries->restore($userId, $id)));
		} catch (GalleryConflictException $exception) {
			return new DataResponse(['code' => 'revision_conflict', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/complete')]
	public function complete(int $id): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present($userId, $this->galleries->complete($userId, $id)));
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
