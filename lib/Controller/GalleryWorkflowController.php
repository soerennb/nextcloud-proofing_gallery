<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\CollectionConflictException;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCA\ProofingGallery\Service\CollaborationService;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\MediaMetadataService;
use OCA\ProofingGallery\Service\MediaSummaryService;
use OCA\ProofingGallery\Service\VersionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUserSession;

final class GalleryWorkflowController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private FolderService $folders,
		private CollectionService $collections,
		private MediaSummaryService $summaries,
		private VersionService $versions,
		private CollaborationService $collaboration,
		private MediaMetadataService $metadata,
		private \OCA\ProofingGallery\Service\ScopedCursorCodec $cursors,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v2/galleries/{id}/selections')]
	public function selectionPage(int $id, int $limit = 50, ?string $cursor = null): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse($this->collaboration->ownerSelectionPage($gallery, $limit, $cursor, $this->cursors));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/selections')]
	public function selections(int $id): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			return new DataResponse(['items' => $this->collaboration->ownerSelections($gallery)]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/selections/{selectionId}')]
	public function updateSelection(int $id, string $selectionId, string $name, string $status): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$this->collaboration->updateOwnerSelection($gallery, $selectionId, $name, $status);
			return new DataResponse([]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{id}/selections/{selectionId}')]
	public function deleteSelection(int $id, string $selectionId): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$this->collaboration->deleteOwnerSelection($gallery, $selectionId);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/selections/{selectionId}/export')]
	public function exportSelection(int $id, string $selectionId, string $format = 'csv', string $fields = ''): Response {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$export = $this->collaboration->exportOwnerSelection($gallery, $selectionId, $format, array_filter(explode(',', $fields)));
			return new DataDownloadResponse($export['content'], $export['filename'], $export['mimeType']);
		} catch (DoesNotExistException|AuthorizationException|InvalidArgumentException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/selections/{selectionId}/xmp')]
	public function exportSelectionXmp(int $id, string $selectionId): DataResponse {
		try {
			$gallery = $this->galleries->get($this->userId(), $id);
			$selection = $this->selection($gallery, $selectionId);
			$fileIds = array_values(array_unique(array_map('intval', $selection['fileIds'])));
			if ($fileIds === [] || count($fileIds) > 200) throw new InvalidArgumentException('Select between 1 and 200 files');
			$state = $this->collaboration->ownerState($gallery);
			$results = [];
			$written = 0;
			foreach ($fileIds as $fileId) {
				try {
					$file = $this->metadataFile($gallery, $fileId);
					$current = $this->metadata->index($file);
					$colorCounts = $state['colorStates'][$fileId] ?? [];
					arsort($colorCounts);
					$label = array_key_first($colorCounts);
					$this->metadata->writeProofingSidecar($file, [
						'galleryId' => $gallery->getId(), 'galleryTitle' => $gallery->getTitle(),
						'selectionId' => $selectionId, 'selectionName' => (string)$selection['name'],
						'likeCount' => (int)($state['likes'][$fileId]['count'] ?? 0),
						'label' => is_string($label) ? $label : null,
					], $file->getEtag(), $current['sidecar']['etag'] ?? null);
					$results[] = ['fileId' => $fileId, 'status' => 'written'];
					$written++;
				} catch (MetadataConflictException|InvalidArgumentException|FolderAccessException $exception) {
					$results[] = ['fileId' => $fileId, 'status' => 'failed', 'message' => $exception->getMessage()];
				}
			}
			return new DataResponse(['written' => $written, 'failed' => count($fileIds) - $written, 'items' => $results]);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/media/{fileId}/versions')]
	public function versions(int $id, int $fileId): DataResponse {
		try {
			return new DataResponse(['items' => $this->versions->list($this->galleries->get($this->userId(), $id), $fileId)]);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/media/{fileId}/versions')]
	public function replaceMedia(int $id, int $fileId): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->get($userId, $id);
			$upload = $this->request->getUploadedFile('file');
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
				throw new InvalidArgumentException('A valid replacement upload is required');
			}
			$this->versions->replace($gallery, $fileId, $upload['tmp_name'], $userId);
			$this->summaries->invalidate($gallery->getId());
			return new DataResponse(['items' => $this->versions->list($gallery, $fileId)], Http::STATUS_CREATED);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/media/{fileId}/versions/{versionId}/restore')]
	public function restoreMediaVersion(int $id, int $fileId, string $versionId): DataResponse {
		try {
			$userId = $this->userId();
			$gallery = $this->galleries->get($userId, $id);
			$this->versions->restore($gallery, $fileId, $versionId, $userId);
			$this->summaries->invalidate($gallery->getId());
			return new DataResponse(['items' => $this->versions->list($gallery, $fileId)]);
		} catch (DoesNotExistException|AuthorizationException|FolderAccessException) {
			return new DataResponse(['message' => 'Gallery item not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/collection')]
	public function collection(int $id): DataResponse {
		try {
			return new DataResponse($this->collections->document($this->galleries->get($this->userId(), $id)));
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
			return new DataResponse($this->collections->replace($this->galleries->get($this->userId(), $id), $revision, $items));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Collection not found'], Http::STATUS_NOT_FOUND);
		} catch (CollectionConflictException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @return array<string, mixed> */
	private function selection(Gallery $gallery, string $selectionId): array {
		foreach ($this->collaboration->ownerSelections($gallery) as $selection) {
			if (hash_equals((string)$selection['id'], $selectionId)) return $selection;
		}
		throw new InvalidArgumentException('Selection not found');
	}

	private function metadataFile(Gallery $gallery, int $fileId): File {
		return $gallery->getSourceType() === 'collection'
			? $this->collections->resolveMedia($gallery, $fileId)
			: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
	}

	private function userId(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new AuthorizationException('Authentication required');
	}
}
