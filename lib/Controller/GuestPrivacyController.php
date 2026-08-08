<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PrivacyService;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ISession;

final class GuestPrivacyController extends ResolvedPublicShareController {
	public function __construct(IRequest $request, ISession $session, PublicShareContextResolver $contextResolver, private GuestService $guests, private PrivacyService $privacy) {
		parent::__construct($request, $session, $contextResolver);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/privacy/export')]
	public function export(): Response {
		try { return $this->privacy->exportGuest($this->authenticate()); }
		catch (DoesNotExistException) { return new JSONResponse(['message' => 'Guest session required'], Http::STATUS_UNAUTHORIZED); }
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'DELETE', url: '/public/{token}/privacy')]
	public function delete(): JSONResponse {
		try {
			$this->privacy->deleteGuest($this->authenticate(true));
			$response = new JSONResponse([], Http::STATUS_NO_CONTENT);
			$response->invalidateCookie(GuestService::COOKIE_NAME);
			return $response;
		} catch (DoesNotExistException) { return new JSONResponse(['message' => 'Guest session required'], Http::STATUS_UNAUTHORIZED); }
		catch (\InvalidArgumentException) { return new JSONResponse(['message' => 'Invalid request nonce'], Http::STATUS_FORBIDDEN); }
	}

	private function authenticate(bool $requireNonce = false): \OCA\ProofingGallery\Db\Guest {
		return $this->guests->authenticate(
			$this->publicContext()->gallery,
			$this->request->getCookie(GuestService::COOKIE_NAME),
			$requireNonce ? $this->request->getHeader('X-Proofing-Nonce') : null,
		);
	}
}
