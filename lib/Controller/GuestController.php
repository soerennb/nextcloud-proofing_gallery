<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;

final class GuestController extends ResolvedPublicShareController {
	public function __construct(
		IRequest $request,
		ISession $session,
		PublicShareContextResolver $contextResolver,
		private GuestService $guests,
	) {
		parent::__construct($request, $session, $contextResolver);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
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
	#[AnonRateLimit(limit: 120, period: 3600)]
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

	private function gallery(): Gallery {
		return $this->publicContext()->gallery;
	}

}
