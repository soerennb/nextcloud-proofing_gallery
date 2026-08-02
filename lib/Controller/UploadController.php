<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCA\ProofingGallery\Service\UploadService;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Domain\PublicLinkCapability;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;

final class UploadController extends ResolvedPublicShareController {
	public function __construct(
		IRequest $request,
		ISession $session,
		PublicShareContextResolver $contextResolver,
		private GuestService $guests,
		private UploadService $uploads,
	) {
		parent::__construct($request, $session, $contextResolver, PublicLinkCapability::Upload);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 3600)]
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
	#[AnonRateLimit(limit: 2000, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/public/{token}/uploads/{uploadId}/chunks/{index}')]
	public function putChunk(string $uploadId, int $index): JSONResponse {
		return $this->mutation(function (Guest $guest) use ($uploadId, $index): array {
			$content = file_get_contents('php://input', false, null, 0, UploadService::CHUNK_SIZE + 1);
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
	#[AnonRateLimit(limit: 120, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/uploads/{uploadId}/finalize')]
	public function finalize(string $uploadId): JSONResponse {
		return $this->mutation(fn (Guest $guest): array => $this->uploads->finalize(
			$this->resolvedGallery(),
			$guest,
			$uploadId,
		));
	}

	private function resolvedGallery(): Gallery {
		return $this->publicContext()->gallery;
	}

	/** @param Http::STATUS_OK|Http::STATUS_CREATED $status */
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
