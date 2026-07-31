<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Exception\CollectionConflictException;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;

final class GalleryController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private FolderService $folders,
		private CollectionService $collections,
		private IPreview $preview,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries')]
	public function index(int $limit = 25, int $offset = 0, bool $archived = false, string $search = ''): DataResponse {
		return new DataResponse($this->galleries->list($this->userId(), $limit, $offset, $archived, $search));
	}

	/** @param array<string, mixed> $settings */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries')]
	public function create(string $title, ?int $folderId = null, array $settings = [], string $sourceType = 'folder'): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->create($userId, $title, $folderId, $settings, $sourceType);
			return new DataResponse($this->galleries->present($userId, $gallery), Http::STATUS_CREATED);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}')]
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
	public function update(int $id, ?string $title = null, ?array $settings = null): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present(
				$userId,
				$this->galleries->update($userId, $id, $title, $settings),
			));
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
			return new DataResponse($this->galleries->present(
				$userId,
				$this->galleries->rebindSource($userId, $id, $folderId),
			));
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
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/restore')]
	public function restore(int $id): DataResponse {
		try {
			$userId = $this->userId();
			return new DataResponse($this->galleries->present($userId, $this->galleries->restore($userId, $id)));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/media')]
	public function media(int $id, int $limit = 60, int $offset = 0, string $path = ''): DataResponse {
		try {
			$gallery = $this->galleries->view($this->userId(), $id);
			if ($gallery->getSourceType() !== 'folder') {
				return new DataResponse(['message' => 'Collection sources cannot be browsed'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			return new DataResponse($this->folders->listMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $limit, $offset, $path));
		} catch (DoesNotExistException|FolderAccessException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery or folder not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/collection')]
	public function collection(int $id): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse($this->collections->document($gallery));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Collection not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param list<array{sourceGalleryId: int, fileId: int}> $items */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/collection')]
	public function updateCollection(int $id, int $revision, array $items): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse($this->collections->replace($gallery, $revision, $items));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Collection not found'], Http::STATUS_NOT_FOUND);
		} catch (CollectionConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/media/{id}/{fileId}/preview')]
	public function preview(int $id, int $fileId, int $x = 560, int $y = 360): DataDisplayResponse {
		try {
			$gallery = $this->galleries->view($this->userId(), $id);
			if ($gallery->getSourceType() === 'collection') {
				try {
					$file = $this->collections->resolveMedia($gallery, $fileId);
				} catch (FolderAccessException $exception) {
					$appearance = GallerySettings::fromArray(json_decode(
						$gallery->getSettings(),
						true,
						flags: JSON_THROW_ON_ERROR,
					))->appearance;
					if (!in_array($fileId, [$appearance['heroFileId'], $appearance['logoFileId']], true)) {
						throw $exception;
					}
					$file = $this->folders->resolveOwnerImage($gallery->getOwnerUid(), $fileId);
				}
			} else {
				$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
			}
			$x = max(64, min(1200, $x));
			$y = max(64, min(1200, $y));
			$preview = $this->preview->getPreview($file, $x, $y, true, IPreview::MODE_COVER);
			return new DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
				'Content-Type' => $preview->getMimeType(),
				'Cache-Control' => 'private, max-age=3600',
				'ETag' => '"' . $file->getEtag() . '"',
			]);
		} catch (DoesNotExistException|FolderAccessException|AuthorizationException|\OCP\Files\NotFoundException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
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
