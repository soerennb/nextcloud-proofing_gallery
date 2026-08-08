<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\MediaSummaryService;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Db\Gallery;
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
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\ZipResponse;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\File;
use OCA\ProofingGallery\Service\GalleryReadinessService;

final class GalleryController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private FolderService $folders,
		private CollectionService $collections,
		private IPreview $preview,
		private MediaSummaryService $summaries,
		private MediaMetadataService $metadata,
		private \OCA\ProofingGallery\Service\PolicyService $policies,
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
	): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->createProject(
				$userId, $title, $purpose, $sourceMode, $folderId, $parentFolderId, $folderName, $settings, $designPreset,
			);
			return new DataResponse($this->galleries->present($userId, $gallery), Http::STATUS_CREATED);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/media')]
	public function media(
		int $id,
		int $limit = 60,
		int $offset = 0,
		string $path = '',
		string $search = '',
		string $sortBy = 'name',
		string $sortDirection = 'asc',
		string $capturedFrom = '',
		string $capturedTo = '',
		string $camera = '',
		string $lens = '',
		string $keyword = '',
		int $ratingMin = 0,
	): DataResponse {
		try {
			$gallery = $this->galleries->view($this->userId(), $id);
			if ($gallery->getSourceType() !== 'folder') {
				return new DataResponse(['message' => 'Collection sources cannot be browsed'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			return new DataResponse($this->folders->listMedia(
				$gallery->getOwnerUid(),
				$gallery->getFolderId(),
				$limit,
				$offset,
				$path,
				$search,
				$sortBy,
				$sortDirection,
				$capturedFrom,
				$capturedTo,
				$camera,
				$lens,
				$keyword,
				$ratingMin,
			));
		} catch (DoesNotExistException|FolderAccessException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery or folder not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}


	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/media/{fileId}/metadata')]
	public function mediaMetadata(int $id, int $fileId, bool $refresh = true): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->get($userId, $id);
			$file = $this->metadataFile($gallery, $fileId);
			return new DataResponse($refresh ? $this->metadata->index($file) : $this->metadata->summary($file));
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param array<string, mixed> $changes */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/media/{fileId}/metadata')]
	public function updateMediaMetadata(
		int $id,
		int $fileId,
		array $changes,
		string $expectedSourceEtag,
		?string $expectedSidecarEtag = null,
	): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->get($userId, $id);
			if ($gallery->getOwnerUid() !== $userId) throw new AuthorizationException('Only the gallery owner can write XMP sidecars');
			return new DataResponse($this->metadata->writeSidecar(
				$this->metadataFile($gallery, $fileId),
				$changes,
				$expectedSourceEtag,
				$expectedSidecarEtag,
			));
		} catch (MetadataConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found or not writable'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/metadata/index')]
	public function indexMetadata(int $id, string $path = ''): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$limit = $this->policies->get('metadataBatchSize');
			$files = [];
			if ($gallery->getSourceType() === 'collection') {
				foreach (array_slice($this->collections->availableItems($gallery), 0, $limit) as $item) {
					$files[] = $this->collections->resolveMedia($gallery, (int)$item['id']);
				}
			} else {
				$page = $this->folders->listMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $limit, 0, $path);
				foreach ($page->items as $item) {
					if (!$item->folder) $files[] = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $item->id);
				}
			}
			$indexed = 0;
			foreach ($files as $file) {
				if (($this->metadata->index($file)['state'] ?? '') === 'ready') $indexed++;
			}
			return new DataResponse(['indexed' => $indexed, 'limit' => $limit]);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery or media not found'], Http::STATUS_NOT_FOUND);
		}
	}

	private function metadataFile(Gallery $gallery, int $fileId): File {
		return $gallery->getSourceType() === 'collection'
			? $this->collections->resolveMedia($gallery, $fileId)
			: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/media/upload')]
	public function uploadMedia(int $id, string $path = ''): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			if ($gallery->getSourceType() !== 'folder') {
				throw new InvalidArgumentException('Files can only be uploaded to folder galleries');
			}
			$upload = $this->request->getUploadedFile('file');
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
				|| !is_string($upload['tmp_name'] ?? null) || !is_string($upload['name'] ?? null)) {
				throw new InvalidArgumentException('A valid file upload is required');
			}
			$item = $this->folders->uploadMedia(
				$gallery->getOwnerUid(), $gallery->getFolderId(), $path, $upload['name'], $upload['tmp_name'],
			);
			$this->summaries->invalidate($gallery->getId());
			return new DataResponse($item, Http::STATUS_CREATED);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/folders')]
	public function createFolder(int $id, string $name, string $path = ''): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			if ($gallery->getSourceType() !== 'folder') {
				throw new InvalidArgumentException('Folders are unavailable for collections');
			}
			return new DataResponse($this->folders->createFolder(
				$gallery->getOwnerUid(), $gallery->getFolderId(), $path, $name,
			), Http::STATUS_CREATED);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/media/{fileId}')]
	public function renameMedia(int $id, int $fileId, string $name): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$item = $this->folders->renameNode($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId, $name);
			$this->summaries->invalidate($gallery->getId());
			return new DataResponse($item);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}/media/{fileId}')]
	public function deleteMedia(int $id, int $fileId): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$this->folders->deleteNode($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
			$this->summaries->invalidate($gallery->getId());
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param list<int> $fileIds */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/media/bulk')]
	public function bulkMedia(int $id, string $action, array $fileIds, string $destinationPath = ''): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			if ($gallery->getSourceType() !== 'folder') {
				throw new InvalidArgumentException('Bulk file actions are unavailable for collections');
			}
			$count = match ($action) {
				'delete' => $this->folders->deleteNodes($gallery->getOwnerUid(), $gallery->getFolderId(), $fileIds),
				'move' => $this->folders->moveNodes($gallery->getOwnerUid(), $gallery->getFolderId(), $fileIds, $destinationPath),
				default => throw new InvalidArgumentException('Unknown bulk action'),
			};
			$this->summaries->invalidate($gallery->getId());
			return new DataResponse(['count' => $count]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/media/download')]
	public function downloadMedia(int $id, string $fileIds): Response {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $fileIds)))));
			if ($ids === [] || count($ids) > 200) {
				throw new InvalidArgumentException('Select between 1 and 200 files');
			}
			$maxBytes = $this->policies->get('maxSelectionBytes');
			$totalBytes = 0;
			$filename = preg_replace('/[^a-z0-9._-]+/i', '-', $gallery->getTitle()) ?: 'gallery';
			$archive = new ZipResponse($this->request, trim($filename, '-') . '-files.zip');
			foreach ($ids as $fileId) {
				$file = $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
				$totalBytes += (int)$file->getSize();
				if ($totalBytes > $maxBytes) throw new InvalidArgumentException('Selection is too large');
				$stream = $file->fopen('rb');
				if (!is_resource($stream)) throw new FolderAccessException('Media file could not be opened');
				$archive->addResource($stream, $fileId . '-' . $file->getName(), (int)$file->getSize(), (int)$file->getMTime());
			}
			return $archive;
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException|InvalidArgumentException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
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
					))->presentation;
					if (!in_array($fileId, [$appearance->heroFileId, $appearance->logoFileId], true)) {
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
