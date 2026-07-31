<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\GuestService;
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

final class GuestController extends PublicShareController {
	private ?IShare $share = null;
	private ?Gallery $gallery = null;

	public function __construct(
		IRequest $request,
		ISession $session,
		private IManager $shareManager,
		private GalleryMapper $galleries,
		private GuestService $guests,
	) {
		parent::__construct(Application::APP_ID, $request, $session);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/session')]
	public function create(string $displayName = '', ?string $email = null): JSONResponse {
		try {
			$session = $this->guests->create($this->gallery(), $displayName, $email);
			$response = new JSONResponse([
				'guest' => $session['guest'],
				'nonce' => $session['nonce'],
				'expiresIn' => 2592000,
			], Http::STATUS_CREATED);
			$response->addCookie(GuestService::COOKIE_NAME, $session['secret'], new \DateTime('+30 days'), 'Lax');
			return $response;
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/session')]
	public function current(): JSONResponse {
		try {
			return new JSONResponse([
				'guest' => $this->guests->authenticate($this->gallery(), $this->request->getCookie(GuestService::COOKIE_NAME)),
			]);
		} catch (DoesNotExistException) {
			// An anonymous visitor is the normal initial state of a public gallery.
			return new JSONResponse(['guest' => null]);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/public/{token}/session')]
	public function destroy(): JSONResponse {
		try {
			$guest = $this->guests->authenticate(
				$this->gallery(),
				$this->request->getCookie(GuestService::COOKIE_NAME),
				$this->request->getHeader('X-Proofing-Nonce'),
			);
			$this->guests->delete($guest);
			$response = new JSONResponse([], Http::STATUS_NO_CONTENT);
			$response->invalidateCookie(GuestService::COOKIE_NAME);
			return $response;
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Guest session not found'], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
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

	private function gallery(): Gallery {
		if ($this->gallery === null) {
			throw new \RuntimeException('Public gallery was not resolved');
		}
		return $this->gallery;
	}

}
