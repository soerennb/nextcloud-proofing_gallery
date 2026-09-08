<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\AuthenticatedCollaborationSession;
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
		private AuthenticatedCollaborationSession $authenticated,
	) {
		parent::__construct($request, $session, $contextResolver);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/session')]
	public function create(string $displayName = '', ?string $email = null): JSONResponse {
		try {
			$authenticated = $this->authenticated->current($this->gallery());
			if ($authenticated !== null) {
				$response = new JSONResponse([
					'guest' => $authenticated['actor'],
					'nonce' => $authenticated['nonce'],
					'expiresIn' => null,
				]);
				$response->addHeader('Cache-Control', 'private, no-store');
				return $response;
			}
			$session = $this->guests->create($this->gallery(), $displayName, $email);
			$response = new JSONResponse([
				'guest' => $session['guest'],
				'nonce' => $session['nonce'],
				'expiresIn' => 2592000,
			], Http::STATUS_CREATED);
			$response->addHeader('Cache-Control', 'private, no-store');
			$response->addCookie(GuestService::cookieName($this->gallery()), $session['secret'], new \DateTime('+30 days'), 'Lax');
			return $response;
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/session')]
	public function current(): JSONResponse {
		$scopedCookieName = GuestService::cookieName($this->gallery());
		$scopedCookiePresent = $this->request->getCookie($scopedCookieName) !== null;
		try {
			$authenticated = $this->authenticated->current($this->gallery());
			if ($authenticated !== null) {
				$response = new JSONResponse([
					'guest' => $authenticated['actor'],
					'nonce' => $authenticated['nonce'],
					'expiresIn' => null,
				]);
				$response->addHeader('Cache-Control', 'private, no-store');
				return $response;
			}
			$secret = $this->guestSecret($this->gallery());
			$session = $this->guests->resume($this->gallery(), $secret);
			$response = new JSONResponse(['guest' => $session['guest'], 'nonce' => $session['nonce'], 'expiresIn' => 2592000]);
			$response->addHeader('Cache-Control', 'private, no-store');
			$response->addCookie(GuestService::cookieName($this->gallery()), (string)$secret, new \DateTime('+30 days'), 'Lax');
			return $response;
		} catch (DoesNotExistException) {
			// An anonymous visitor is the normal initial state of a public gallery.
			$response = new JSONResponse(['guest' => null]);
			$response->addHeader('Cache-Control', 'private, no-store');
			// Do not emit a deletion for a cookie that was absent from this request.
			// A just-created session can otherwise be overwritten by a late
			// anonymous bootstrap request from the public app.
			if ($scopedCookiePresent) $response->invalidateCookie($scopedCookieName);
			return $response;
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
				$this->guestSecret($this->gallery()),
				$this->request->getHeader('X-Proofing-Nonce'),
			);
			$this->guests->delete($guest);
			$response = new JSONResponse([], Http::STATUS_NO_CONTENT);
			$response->invalidateCookie(GuestService::cookieName($this->gallery()));
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
