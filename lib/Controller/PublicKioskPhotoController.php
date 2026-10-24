<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\Db\KioskRepository;
use OCA\ProofingGallery\Service\PublicMediaResolver;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;

/**
 * Resolves the photo ID a Fotobox printed in its QR code before the photo was uploaded.
 * The random UUID only maps to a file of the same public gallery, which every guest with
 * the link can browse anyway; until the upload arrives the guest page keeps waiting.
 */
final class PublicKioskPhotoController extends ResolvedPublicShareController {
	public function __construct(
		IRequest $request,
		ISession $session,
		PublicShareContextResolver $contextResolver,
		private KioskRepository $kiosks,
		private PublicMediaResolver $publicMedia,
	) {
		parent::__construct($request, $session, $contextResolver);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 1200, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/kiosk-photos/{photoId}')]
	public function show(string $photoId): JSONResponse {
		$photoId = strtolower($photoId);
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $photoId) !== 1) {
			return $this->noStore(new JSONResponse(['status' => 'invalid'], Http::STATUS_NOT_FOUND));
		}
		$context = $this->publicContext();
		$receipt = $this->kiosks->photo((int)$context->gallery->getId(), $photoId);
		if ($receipt === null || $receipt['file_id'] === null) {
			$response = $this->noStore(new JSONResponse(['status' => 'pending'], Http::STATUS_ACCEPTED));
			$response->addHeader('Retry-After', '5');
			return $response;
		}
		try {
			$file = $this->publicMedia->resolve($context, (int)$receipt['file_id']);
		} catch (NotFoundException) {
			// Deleted or hidden after the upload; the guest page says so instead of waiting forever.
			return $this->noStore(new JSONResponse(['status' => 'gone'], Http::STATUS_GONE));
		}
		return $this->noStore(new JSONResponse(['status' => 'stored', 'fileId' => (int)$file->getId()]));
	}

	private function noStore(JSONResponse $response): JSONResponse {
		$response->addHeader('Cache-Control', 'private, no-store');
		return $response;
	}
}
