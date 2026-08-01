<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\UploadService;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\PublicShareController;
use OCP\IRequest;
use OCP\ISession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

final class UploadController extends PublicShareController {
	private ?IShare $share = null;
	private ?Gallery $gallery = null;
	private ?PublicLink $publicLink = null;

	public function __construct(
		IRequest $request,
		ISession $session,
		private IManager $shareManager,
		private GalleryMapper $galleries,
		private PublicLinkMapper $publicLinks,
		private \OCA\ProofingGallery\Service\PublicLinkPolicyService $linkPolicies,
		private GuestService $guests,
		private UploadService $uploads,
	) {
		parent::__construct(Application::APP_ID, $request, $session);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/uploads')]
	public function initiate(string $filename, string $mimeType, int $size): JSONResponse {
		return $this->mutation(
			fn (Guest $guest): array => $this->uploads->initiate(
				$this->resolvedGallery(),
				$guest,
				$filename,
				$mimeType,
				$size,
			),
			Http::STATUS_CREATED,
		);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/uploads/{uploadId}')]
	public function status(string $uploadId): JSONResponse {
		return $this->mutation(fn (Guest $guest): array => $this->uploads->status(
			$this->resolvedGallery(),
			$guest,
			$uploadId,
		));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/public/{token}/uploads/{uploadId}/chunks/{index}')]
	public function putChunk(string $uploadId, int $index): JSONResponse {
		return $this->mutation(function (Guest $guest) use ($uploadId, $index): array {
			$content = file_get_contents('php://input');
			if ($content === false) {
				throw new InvalidArgumentException('Upload chunk could not be read');
			}
			$this->uploads->putChunk(
				$this->resolvedGallery(),
				$guest,
				$uploadId,
				$index,
				$content,
			);
			return [];
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/uploads/{uploadId}/finalize')]
	public function finalize(string $uploadId): JSONResponse {
		return $this->mutation(fn (Guest $guest): array => $this->uploads->finalize(
			$this->resolvedGallery(),
			$guest,
			$uploadId,
		));
	}

	public function isValidToken(): bool {
		try {
			$this->share = $this->shareManager->getShareByToken($this->getToken());
			$this->publicLink = $this->publicLinks->findByToken($this->getToken());
			if ($this->publicLink->getStatus() !== 'active') return false;
			$policy = $this->linkPolicies->validate(json_decode($this->publicLink->getPolicy(), true, flags: JSON_THROW_ON_ERROR));
			$this->gallery = $this->galleries->find($this->publicLink->getGalleryId());
			return $policy['upload'] && $this->share->getNode() instanceof \OCP\Files\Folder;
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
		} catch (PolicyViolationException $exception) {
			return new JSONResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
