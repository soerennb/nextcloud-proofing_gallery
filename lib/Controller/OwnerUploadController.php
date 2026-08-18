<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\BackgroundJob\IndexMediaMetadataJob;
use OCA\ProofingGallery\BackgroundJob\RebuildMediaIndexJob;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\UploadBusyException;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\MediaSummaryService;
use OCA\ProofingGallery\Service\OwnerUploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;

final class OwnerUploadController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private OwnerUploadService $uploads,
		private MediaSummaryService $summaries,
		private IJobList $jobs,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/owner-uploads')]
	public function initiate(int $id, string $filename, string $mimeType, int $size, string $path = '', string $conflict = 'fail'): DataResponse {
		return $this->respond(fn () => $this->uploads->initiate($this->galleries->get($this->userId(), $id), $this->userId(), $filename, $mimeType, $size, $path, $conflict), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/owner-uploads/{uploadId}')]
	public function status(int $id, string $uploadId): DataResponse {
		return $this->respond(fn () => $this->uploads->status($this->galleries->get($this->userId(), $id), $this->userId(), $uploadId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/galleries/{id}/owner-uploads/{uploadId}/chunks/{index}')]
	public function putChunk(int $id, string $uploadId, int $index): DataResponse {
		return $this->respond(function () use ($id, $uploadId, $index): array {
			$content = file_get_contents('php://input', false, null, 0, OwnerUploadService::CHUNK_SIZE + 1);
			if ($content === false) throw new InvalidArgumentException('Upload chunk could not be read');
			$this->uploads->putChunk($this->galleries->get($this->userId(), $id), $this->userId(), $uploadId, $index, $content);
			return [];
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{id}/owner-uploads/{uploadId}/finalize')]
	public function finalize(int $id, string $uploadId): DataResponse {
		return $this->respond(function () use ($id, $uploadId): array {
			$gallery = $this->galleries->get($this->userId(), $id);
			$result = $this->uploads->finalize($gallery, $this->userId(), $uploadId);
			$this->summaries->invalidate((int)$gallery->getId());
			if (isset($result['item'])) {
				$this->jobs->add(IndexMediaMetadataJob::class, ['galleryId' => (int)$gallery->getId(), 'fileId' => $result['item']->id]);
				$this->jobs->add(RebuildMediaIndexJob::class, ['galleryId' => (int)$gallery->getId()]);
			}
			return $result;
		});
	}

	/** @param Http::STATUS_OK|Http::STATUS_CREATED $status */
	private function respond(callable $callback, int $status = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($callback(), $status);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		} catch (UploadBusyException|LockedException $exception) {
			return new DataResponse(['code' => 'upload_busy', 'message' => $exception->getMessage()], Http::STATUS_LOCKED, ['Retry-After' => '1']);
		} catch (InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
