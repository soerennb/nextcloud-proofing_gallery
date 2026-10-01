<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\GalleryNotReadyException;
use OCA\ProofingGallery\Exception\KioskConflictException;
use OCA\ProofingGallery\Exception\KioskRateLimitException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Exception\UploadBusyException;
use OCA\ProofingGallery\Service\KioskGalleryService;
use OCA\ProofingGallery\Service\KioskLinkService;
use OCA\ProofingGallery\Service\KioskPhotoService;
use OCA\ProofingGallery\Service\KioskUploadBodyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;

/** Authenticated OCS routes; UI sessions and app-password clients share policy. */
final class KioskController extends OCSController {
	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private KioskGalleryService $galleries,
		private KioskLinkService $links,
		private KioskPhotoService $photos,
		private KioskUploadBodyService $bodies,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function setup(): DataResponse {
		return $this->respond(fn (): array => $this->galleries->setup($this->userId()));
	}

	#[NoAdminRequired]
	public function create(string $eventId, string $title, ?int $parentFolderId = null, ?int $designPresetId = null, string $password = '', string $expiresAt = ''): DataResponse {
		return $this->mutation(fn (): array => $this->galleries->create($this->userId(), $eventId, $title, $parentFolderId, $designPresetId, $password, $expiresAt));
	}

	#[NoAdminRequired]
	public function upload(int $galleryId, string $photoId): DataResponse {
		return $this->mutation(function () use ($galleryId, $photoId): array {
			$gallery = $this->links->gallery($this->userId(), $galleryId);
			if (strtolower(trim(explode(';', $this->request->getHeader('Content-Type'))[0])) !== 'image/jpeg') throw new \InvalidArgumentException('Content-Type must be image/jpeg');
			$input = fopen('php://input', 'rb');
			if ($input === false) throw new \InvalidArgumentException('The upload body could not be read');
			try { $body = $this->bodies->receive($input); } finally { fclose($input); }
			try { return $this->photos->upload($gallery, $photoId, $body['path'], $body['checksum']); } finally { @unlink($body['path']); }
		});
	}

	/** @param callable(): array{data: array<string, mixed>, replayed: bool} $callback */
	private function mutation(callable $callback): DataResponse {
		return $this->respond(function () use ($callback): array {
			$result = $callback();
			return [...$result['data'], 'replayed' => $result['replayed']];
		}, true);
	}

	/** @param callable(): array<string, mixed> $callback */
	private function respond(callable $callback, bool $mutation = false): DataResponse {
		try {
			$data = $callback();
			return new DataResponse($data, $mutation && !($data['replayed'] ?? false) ? Http::STATUS_CREATED : Http::STATUS_OK);
		} catch (\OCP\Files\NotFoundException|DoesNotExistException|ShareNotFound) {
			return new DataResponse(['code' => 'not_found', 'message' => 'Gallery, folder or design not found'], Http::STATUS_NOT_FOUND);
		} catch (KioskConflictException $exception) {
			return new DataResponse(['code' => 'conflict', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (KioskRateLimitException $exception) {
			return new DataResponse(['code' => 'rate_limited', 'message' => $exception->getMessage()], Http::STATUS_TOO_MANY_REQUESTS, ['Retry-After' => '60']);
		} catch (UploadBusyException|\OCP\Lock\LockedException $exception) {
			return new DataResponse(['code' => 'busy', 'message' => $exception->getMessage()], Http::STATUS_LOCKED, ['Retry-After' => '1']);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\OCP\HintException|\OCP\Share\Exceptions\GenericShareException|GalleryNotReadyException|\InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['code' => 'invalid_request', 'message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->session->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
