<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Service\CollaborationService;
use OCA\ProofingGallery\Service\GuestService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\PublicShareController;
use OCP\IRequest;
use OCP\ISession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

final class CollaborationController extends PublicShareController {
	private ?IShare $share = null;
	private ?Gallery $gallery = null;

	public function __construct(
		IRequest $request,
		ISession $session,
		private IManager $shareManager,
		private GalleryMapper $galleries,
		private GuestService $guests,
		private CollaborationService $collaboration,
	) {
		parent::__construct(Application::APP_ID, $request, $session);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/collaboration')]
	public function state(int $cursor = 0): JSONResponse {
		return new JSONResponse($this->collaboration->state($this->resolvedGallery(), $this->optionalGuest(), $cursor));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/collaboration/media/{fileId}/like')]
	public function toggleLike(int $fileId): JSONResponse {
		return $this->mutation(fn (Guest $guest): array => [
			'liked' => $this->collaboration->toggleLike($this->resolvedGallery(), $guest, $fileId),
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/public/{token}/collaboration/media/{fileId}/color')]
	public function setColor(int $fileId, ?string $value = null): JSONResponse {
		return $this->mutation(function (Guest $guest) use ($fileId, $value): array {
			$this->collaboration->setColor($this->resolvedGallery(), $guest, $fileId, $value);
			return [];
		});
	}

	/** @param array<string, int>|null $annotation */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/collaboration/media/{fileId}/comments')]
	public function addComment(int $fileId, string $body, ?array $annotation = null): JSONResponse {
		return $this->mutation(fn (Guest $guest): array => [
			'id' => $this->collaboration->addComment($this->resolvedGallery(), $guest, $fileId, $body, $annotation),
		], Http::STATUS_CREATED);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/public/{token}/collaboration/comments/{commentId}')]
	public function deleteComment(int $commentId): JSONResponse {
		return $this->mutation(function (Guest $guest) use ($commentId): array {
			$this->collaboration->deleteComment($this->resolvedGallery(), $guest, $commentId);
			return [];
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/public/{token}/collaboration/comments/{commentId}')]
	public function updateComment(int $commentId, string $body): JSONResponse {
		return $this->mutation(function (Guest $guest) use ($commentId, $body): array {
			$this->collaboration->updateComment($this->resolvedGallery(), $guest, $commentId, $body);
			return [];
		});
	}

	/** @param list<int> $fileIds */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/collaboration/selections')]
	public function saveSelection(string $name, string $message = '', array $fileIds = []): JSONResponse {
		return $this->mutation(fn (Guest $guest): array => [
			'id' => $this->collaboration->saveSelection(
				$this->resolvedGallery(),
				$guest,
				$name,
				$message,
				$fileIds,
			),
		], Http::STATUS_CREATED);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/collaboration/selections/{selectionId}/export')]
	public function exportSelection(string $selectionId, string $format = 'csv'): Response {
		try {
			$guest = $this->guests->authenticate(
				$this->resolvedGallery(),
				$this->request->getCookie(GuestService::COOKIE_NAME),
			);
			$export = $this->collaboration->exportSelection(
				$this->resolvedGallery(),
				$guest,
				$selectionId,
				$format,
			);
			return new DataDownloadResponse(
				$export['content'],
				$export['filename'],
				$export['mimeType'],
				headers: ['Cache-Control' => 'private, no-store'],
			);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Guest session required'], Http::STATUS_UNAUTHORIZED);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	public function isValidToken(): bool {
		try {
			$this->share = $this->shareManager->getShareByToken($this->getToken());
			$this->gallery = $this->galleries->findByShareToken($this->getToken());
			return $this->share->getNodeId() === $this->gallery->getFolderId();
		} catch (ShareNotFound|DoesNotExistException) {
			return false;
		}
	}

	protected function isPasswordProtected(): bool {
		return $this->share?->getPassword() !== null;
	}

	protected function getPasswordHash(): ?string {
		return $this->share?->getPassword();
	}

	private function resolvedGallery(): Gallery {
		if ($this->gallery === null) {
			throw new \RuntimeException('Public gallery was not resolved');
		}
		return $this->gallery;
	}

	private function optionalGuest(): ?Guest {
		try {
			return $this->guests->authenticate(
				$this->resolvedGallery(),
				$this->request->getCookie(GuestService::COOKIE_NAME),
			);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private function mutation(callable $callback, int $status = Http::STATUS_OK): JSONResponse {
		try {
			$guest = $this->guests->authenticate(
				$this->resolvedGallery(),
				$this->request->getCookie(GuestService::COOKIE_NAME),
				$this->request->getHeader('X-Proofing-Nonce'),
			);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Guest session required'], Http::STATUS_UNAUTHORIZED);
		} catch (InvalidArgumentException) {
			return new JSONResponse(['message' => 'Invalid request nonce'], Http::STATUS_FORBIDDEN);
		}
		try {
			return new JSONResponse($callback($guest), $status);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
