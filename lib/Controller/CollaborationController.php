<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Service\CollaborationService;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ISession;

final class CollaborationController extends ResolvedPublicShareController {
	public function __construct(
		IRequest $request,
		ISession $session,
		PublicShareContextResolver $contextResolver,
		private \OCA\ProofingGallery\Service\PublicMediaResolver $publicMedia,
		private GuestService $guests,
		private CollaborationService $collaboration,
		private \OCA\ProofingGallery\Service\GuestRatingService $guestRatings,
		private \OCA\ProofingGallery\Service\CapabilityPolicyService $capabilities,
		private \OCA\ProofingGallery\Service\ShareAuditService $shareAudit,
	) {
		parent::__construct($request, $session, $contextResolver);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/collaboration')]
	public function state(int $cursor = 0): JSONResponse {
		$state = $this->collaboration->state($this->resolvedGallery(), $this->optionalGuest(), $cursor);
		$policy = $this->policy();
		foreach (['likes', 'colors', 'comments', 'annotations', 'selections'] as $feature) {
			$state['policy']['features'][$feature] = ($state['policy']['features'][$feature] ?? true) && $policy[$feature];
		}
		$state['likes'] = array_filter($state['likes'], fn (mixed $_, int $fileId): bool => $this->allowsFile($fileId), ARRAY_FILTER_USE_BOTH);
		$state['colors'] = array_filter($state['colors'], fn (mixed $_, int $fileId): bool => $this->allowsFile($fileId), ARRAY_FILTER_USE_BOTH);
		$state['colorStates'] = array_filter($state['colorStates'], fn (mixed $_, int $fileId): bool => $this->allowsFile($fileId), ARRAY_FILTER_USE_BOTH);
		$state['comments'] = array_values(array_filter($state['comments'], fn (array $comment): bool => $this->allowsFile((int)$comment['fileId'])));
		foreach ($state['selections'] as &$selection) $selection['fileIds'] = array_values(array_filter($selection['fileIds'], fn (int $fileId): bool => $this->allowsFile($fileId)));
		unset($selection);
		$state['events'] = array_values(array_filter($state['events'], fn (array $event): bool => !isset($event['payload']['fileId']) || $this->allowsFile((int)$event['payload']['fileId'])));
		$guest = $this->optionalGuest();
		$state['ratings'] = $guest === null || !$this->ratingEnabled()
			? []
			: array_values(array_map(
				static fn ($rating): array => $rating->jsonSerialize(),
				array_filter($this->guestRatings->forGuest($guest), fn ($rating): bool => $this->allowsFile($rating->getFileId())),
			));
		return new JSONResponse($state);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/public/{token}/collaboration/media/{fileId}/rating')]
	public function setRating(int $fileId, int $rating = 0, string $pick = 'none'): JSONResponse {
		if (!$this->ratingEnabled()) return new JSONResponse(['message' => 'Guest ratings are disabled for this link'], Http::STATUS_FORBIDDEN);
		if (!$this->allowsFile($fileId)) return new JSONResponse(['message' => 'Media not found'], Http::STATUS_NOT_FOUND);
		try {
			$guest = $this->guests->authenticate($this->resolvedGallery(), $this->request->getCookie(GuestService::COOKIE_NAME), $this->request->getHeader('X-Proofing-Nonce'));
			$permissions = $this->ratingPermissions();
			$current = null;
			foreach ($this->guestRatings->forGuest($guest) as $saved) if ($saved->getFileId() === $fileId) { $current = $saved; break; }
			$rating = $permissions['ratings'] ? $rating : ($current?->getRating() ?? 0);
			$pick = $permissions['pick'] ? $pick : ($current?->getPickState() ?? 'none');
			$value = $this->guestRatings->save($this->resolvedPublicLink(), $guest, $fileId, $rating, $pick);
			$this->shareAudit->record($this->resolvedPublicLink(), 'feedback', $guest->getId(), fileId: $fileId);
			return new JSONResponse($value);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Guest session required'], Http::STATUS_UNAUTHORIZED);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/collaboration/media/{fileId}/like')]
	public function toggleLike(int $fileId): JSONResponse {
		if (!$this->allowsFile($fileId)) return new JSONResponse(['message' => 'Media not found'], Http::STATUS_NOT_FOUND);
		return $this->mutation('likes', fn (Guest $guest): array => [
			'liked' => $this->collaboration->toggleLike($this->resolvedGallery(), $guest, $fileId),
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/public/{token}/collaboration/media/{fileId}/color')]
	public function setColor(int $fileId, ?string $value = null): JSONResponse {
		if (!$this->allowsFile($fileId)) return new JSONResponse(['message' => 'Media not found'], Http::STATUS_NOT_FOUND);
		return $this->mutation('colors', function (Guest $guest) use ($fileId, $value): array {
			$this->collaboration->setColor($this->resolvedGallery(), $guest, $fileId, $value);
			return [];
		});
	}

	/** @param array<string, int>|null $annotation */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/collaboration/media/{fileId}/comments')]
	public function addComment(int $fileId, string $body, ?array $annotation = null): JSONResponse {
		if (!$this->allowsFile($fileId)) return new JSONResponse(['message' => 'Media not found'], Http::STATUS_NOT_FOUND);
		return $this->mutation('comments', fn (Guest $guest): array => [
			'id' => $this->collaboration->addComment($this->resolvedGallery(), $guest, $fileId, $body, $annotation),
		], Http::STATUS_CREATED);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 3600)]
	#[FrontpageRoute(verb: 'DELETE', url: '/public/{token}/collaboration/comments/{commentId}')]
	public function deleteComment(int $commentId): JSONResponse {
		return $this->mutation('comments', function (Guest $guest) use ($commentId): array {
			if (!$this->allowsFile($this->collaboration->ownedCommentFileId($this->resolvedGallery(), $guest, $commentId))) {
				throw new InvalidArgumentException('Comment not found');
			}
			$this->collaboration->deleteComment($this->resolvedGallery(), $guest, $commentId);
			return [];
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/public/{token}/collaboration/comments/{commentId}')]
	public function updateComment(int $commentId, string $body): JSONResponse {
		return $this->mutation('comments', function (Guest $guest) use ($commentId, $body): array {
			if (!$this->allowsFile($this->collaboration->ownedCommentFileId($this->resolvedGallery(), $guest, $commentId))) {
				throw new InvalidArgumentException('Comment not found');
			}
			$this->collaboration->updateComment($this->resolvedGallery(), $guest, $commentId, $body);
			return [];
		});
	}

	/** @param list<int> $fileIds */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/public/{token}/collaboration/selections')]
	public function saveSelection(string $name, string $message = '', array $fileIds = []): JSONResponse {
		foreach ($fileIds as $fileId) if (!$this->allowsFile((int)$fileId)) return new JSONResponse(['message' => 'Media not found'], Http::STATUS_NOT_FOUND);
		return $this->mutation('selections', fn (Guest $guest): array => [
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
	public function exportSelection(string $selectionId, string $format = 'csv', string $fields = ''): Response {
		if (!$this->policy()['export']) return new JSONResponse(['message' => 'Export is disabled for this link'], Http::STATUS_FORBIDDEN);
		try {
			$guest = $this->guests->authenticate(
				$this->resolvedGallery(),
				$this->request->getCookie(GuestService::COOKIE_NAME),
			);
			foreach ($this->collaboration->guestSelectionFileIds($this->resolvedGallery(), $guest, $selectionId) as $fileId) {
				if (!$this->allowsFile($fileId)) throw new InvalidArgumentException('Selection not found');
			}
			$export = $this->collaboration->exportSelection(
				$this->resolvedGallery(),
				$guest,
				$selectionId,
				$format,
				array_filter(explode(',', $fields)),
			);
			return new DataDownloadResponse(
				$export['content'],
				$export['filename'],
				$export['mimeType'],
				headers: ['Cache-Control' => 'private, no-store'],
			);
		} catch (DoesNotExistException) {
			return new JSONResponse(['message' => 'Guest session required'], Http::STATUS_UNAUTHORIZED);
		} catch (PolicyViolationException $exception) {
			return new JSONResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	private function resolvedGallery(): Gallery {
		return $this->publicContext()->gallery;
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

	/** @param Http::STATUS_OK|Http::STATUS_CREATED $status */
	private function mutation(string $feature, callable $callback, int $status = Http::STATUS_OK): JSONResponse {
		if (!$this->policy()[$feature]) return new JSONResponse(['message' => 'This action is disabled for this link'], Http::STATUS_FORBIDDEN);
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

	/** @return array<string, bool|string> */
	private function policy(): array {
		return $this->publicContext()->policy->jsonSerialize();
	}

	private function ratingEnabled(): bool {
		$permissions = $this->ratingPermissions();
		return $permissions['ratings'] || $permissions['pick'];
	}

	/** @return array{ratings: bool, pick: bool} */
	private function ratingPermissions(): array {
		$settings = $this->publicContext()->settings;
		$enabled = $this->capabilities->feature('guestRatings');
		return [
			'ratings' => $enabled && $settings->review->ratings && $this->policy()['ratings'],
			'pick' => $enabled && $settings->review->pick && $this->policy()['pick'],
		];
	}

	private function allowsFile(int $fileId): bool {
		return $this->publicMedia->allows($this->publicContext(), $fileId);
	}

	private function resolvedPublicLink(): PublicLink {
		return $this->publicContext()->link;
	}
}
