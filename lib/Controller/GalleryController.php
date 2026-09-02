<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\UploadBusyException;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\MediaSummaryService;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Service\DesignAssetService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Exception\ProjectCreationException;
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
use OCA\ProofingGallery\Service\BrandingAssetService;
use OCA\ProofingGallery\Service\WatermarkPreviewService;

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
		private BrandingAssetService $branding,
		private DesignAssetService $designAssets,
		private WatermarkPreviewService $watermarks,
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
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/story-media')]
	public function storyMedia(int $id, string $fileIds): DataResponse {
		try {
			$gallery = $this->galleries->view($this->userId(), $id);
			$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $fileIds)))));
			if ($ids === [] || count($ids) > 240) throw new InvalidArgumentException('Select between 1 and 240 story files');
			$items = [];
			$missing = [];
			foreach ($ids as $fileId) {
				try {
					$file = $gallery->getSourceType() === 'collection'
						? $this->collections->resolveMedia($gallery, $fileId)
						: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
					$items[] = [
						'id' => $fileId, 'name' => $file->getName(), 'mimeType' => $file->getMimeType(),
						'size' => (int)$file->getSize(), 'modifiedAt' => $file->getMTime(), 'etag' => $file->getEtag(), 'folder' => false,
					];
				} catch (DoesNotExistException|FolderAccessException|\OCP\Files\NotFoundException) {
					$missing[] = $fileId;
				}
			}
			return new DataResponse(['items' => $items, 'missingIds' => $missing]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
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
		} catch (UploadBusyException|\OCP\Lock\LockedException $exception) {
			return new DataResponse(['code' => 'upload_busy', 'message' => $exception->getMessage()], Http::STATUS_LOCKED, ['Retry-After' => '1']);
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

	/** @param list<string> $paths */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/folders/ensure')]
	public function ensureFolders(int $id, array $paths): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			if ($gallery->getSourceType() !== 'folder') throw new InvalidArgumentException('Folders are unavailable for collections');
			return new DataResponse(['paths' => $this->folders->ensureFolders($gallery->getOwnerUid(), $gallery->getFolderId(), $paths)]);
		} catch (DoesNotExistException|AuthorizationException) { return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND); }
		catch (InvalidArgumentException|FolderAccessException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
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
	#[FrontpageRoute(verb: 'GET', url: '/media/{id}/asset/logo')]
	public function brandLogo(int $id): DataDisplayResponse {
		try {
			$gallery = $this->galleries->view($this->userId(), $id);
			$presentation = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR))->presentation;
			if ($presentation->logoMode === 'upload' && $presentation->logoAssetId !== null) {
				$asset = $this->designAssets->owned($gallery->getOwnerUid(), $presentation->logoAssetId, 'logo');
				return new DataDisplayResponse($this->designAssets->content($asset), Http::STATUS_OK, [
					'Content-Type' => $asset->getMimeType(),
					'Cache-Control' => 'private, max-age=3600',
					'X-Content-Type-Options' => 'nosniff',
				]);
			}
			if ($presentation->logoMode === 'inherit' && $presentation->instanceLogoAssetId !== null) {
				return new DataDisplayResponse($this->branding->get($presentation->instanceLogoAssetId)->getContent(), Http::STATUS_OK, [
					'Content-Type' => $this->branding->mimeType($presentation->instanceLogoAssetId),
					'Cache-Control' => 'private, max-age=3600',
				]);
			}
			if ($presentation->logoMode !== 'gallery' || $presentation->logoFileId === null) {
				return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
			}
			$file = $gallery->getSourceType() === 'collection'
				? $this->collections->resolveMedia($gallery, $presentation->logoFileId)
				: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $presentation->logoFileId);
			$preview = $this->preview->getPreview($file, 480, 160, false, IPreview::MODE_FILL);
			return new DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
				'Content-Type' => $preview->getMimeType(),
				'Cache-Control' => 'private, max-age=3600',
				'ETag' => '"' . $file->getEtag() . '"',
			]);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException|InvalidArgumentException|\OCP\Files\NotFoundException|\JsonException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/media/{id}/{fileId}/preview')]
	public function preview(int $id, int $fileId, int $x = 560, int $y = 360, string $mode = 'cover'): DataDisplayResponse {
		if (!in_array($mode, ['cover', 'fit'], true)) {
			return new DataDisplayResponse('', Http::STATUS_BAD_REQUEST);
		}
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
			$x = max(64, min(2400, $x));
			$y = max(64, min(2400, $y));
			$preview = $this->preview->getPreview(
				$file,
				$x,
				$y,
				$mode === 'cover',
				$mode === 'cover' ? IPreview::MODE_COVER : IPreview::MODE_FILL,
			);
			return new DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
				'Content-Type' => $preview->getMimeType(),
				'Cache-Control' => 'private, max-age=3600',
				'ETag' => '"' . $file->getEtag() . '"',
			]);
		} catch (DoesNotExistException|FolderAccessException|AuthorizationException|\OCP\Files\NotFoundException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/media/{id}/{fileId}/design-preview')]
	public function designPreview(
		int $id,
		int $fileId,
		int $x = 560,
		int $y = 360,
		string $mode = 'cover',
		string $presentation = '{}',
	): DataDisplayResponse {
		if (!in_array($mode, ['cover', 'fit'], true)) return new DataDisplayResponse('', Http::STATUS_BAD_REQUEST);
		try {
			$gallery = $this->galleries->view($this->userId(), $id);
			$file = $gallery->getSourceType() === 'collection'
				? $this->collections->resolveMedia($gallery, $fileId)
				: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
			$patch = json_decode($presentation, true, flags: JSON_THROW_ON_ERROR);
			if (!is_array($patch)) throw new InvalidArgumentException('Invalid presentation settings');
			$current = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$settings = GallerySettings::merge($current, ['presentation' => $patch])->presentation;
			$derivative = $this->watermarks->render(
				$file,
				max(64, min(2400, $x)),
				max(64, min(2400, $y)),
				$settings,
				$gallery->getOwnerUid(),
				$mode,
			);
			return new DataDisplayResponse($derivative['content'], Http::STATUS_OK, [
				'Content-Type' => $derivative['mimeType'],
				'Cache-Control' => 'private, max-age=3600',
				'ETag' => '"' . $derivative['etag'] . '"',
				'X-Proofing-Derivative-Cache' => $derivative['cached'] ? 'hit' : 'miss',
			]);
		} catch (DoesNotExistException|FolderAccessException|AuthorizationException|\OCP\Files\NotFoundException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		} catch (\JsonException|InvalidArgumentException) {
			return new DataDisplayResponse('', Http::STATUS_BAD_REQUEST);
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
