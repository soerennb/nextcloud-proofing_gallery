<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\Domain\CollaborationActor;
use OCA\ProofingGallery\Exception\ReviewConflictException;
use OCA\ProofingGallery\Service\AuthenticatedCollaborationSession;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCA\ProofingGallery\Service\ReviewWorkflowService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;

final class PublicReviewController extends ResolvedPublicShareController {
	public function __construct(
		IRequest $request,
		ISession $session,
		PublicShareContextResolver $contextResolver,
		private ReviewWorkflowService $reviews,
		private GuestService $guests,
		private AuthenticatedCollaborationSession $authenticated,
	) {
		parent::__construct($request, $session, $contextResolver);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/review')]
	public function state(): JSONResponse {
		$context = $this->publicContext();
		$actor = $this->authenticated->actor();
		if ($actor === null) {
			try {
				$actor = CollaborationActor::guest($this->guests->authenticate($context->gallery, $this->guestSecret($context->gallery)));
			} catch (DoesNotExistException) {}
		}
		return new JSONResponse($this->reviews->publicState($context->gallery, $context->link, $actor));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/review/submit')]
	public function submit(): JSONResponse {
		try {
			$context = $this->publicContext();
			$actor = $this->authenticateActor($context->gallery);
			return new JSONResponse($this->reviews->submit($context->gallery, $context->link, $actor));
		} catch (DoesNotExistException) {
			return new JSONResponse(['code' => 'guest_session_required', 'message' => 'Collaboration identity required'], Http::STATUS_UNAUTHORIZED);
		} catch (ReviewConflictException $exception) {
			return new JSONResponse(['code' => 'review_conflict', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $exception) {
			if ($exception->getMessage() === 'Invalid request nonce') {
				return new JSONResponse(['code' => 'invalid_nonce', 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
			}
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function authenticateActor(\OCA\ProofingGallery\Db\Gallery $gallery): CollaborationActor {
		$actor = $this->authenticated->authenticate($gallery, $this->request->getHeader('X-Proofing-Nonce'));
		if ($actor !== null) return $actor;
		return CollaborationActor::guest($this->guests->authenticate(
			$gallery,
			$this->guestSecret($gallery),
			$this->request->getHeader('X-Proofing-Nonce'),
		));
	}
}
