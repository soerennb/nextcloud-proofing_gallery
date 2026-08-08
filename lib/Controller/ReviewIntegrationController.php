<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\ReviewIntegrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class ReviewIntegrationController extends Controller {
	public function __construct(IRequest $request, private ReviewIntegrationService $integrations, private IUserSession $session) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{galleryId}/review-integrations')]
	public function status(int $galleryId): DataResponse { return $this->respond(fn (): array => $this->integrations->status($this->userId(), $galleryId)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{galleryId}/public-links/{linkId}/review-integrations/calendar')]
	public function calendar(int $galleryId, int $linkId, string $calendarUri): DataResponse { return $this->respond(fn (): array => $this->integrations->createCalendarEvent($this->userId(), $galleryId, $linkId, $calendarUri)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{galleryId}/public-links/{linkId}/review-integrations/deck')]
	public function deck(int $galleryId, int $linkId, int $boardId, int $stackId, int $cardId): DataResponse { return $this->respond(fn (): array => $this->integrations->registerDeckCard($this->userId(), $galleryId, $linkId, $boardId, $stackId, $cardId)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/galleries/{galleryId}/public-links/{linkId}/review-integrations/talk')]
	public function talk(int $galleryId, int $linkId): DataResponse { return $this->respond(fn (): array => $this->integrations->createTalkConversation($this->userId(), $galleryId, $linkId)); }

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/galleries/{galleryId}/public-links/{linkId}/review-integrations/talk')]
	public function deleteTalk(int $galleryId, int $linkId): DataResponse { return $this->respond(fn (): array => $this->integrations->deleteTalkConversation($this->userId(), $galleryId, $linkId)); }

	/** @param callable(): array<string, mixed> $callback */
	private function respond(callable $callback): DataResponse {
		try { return new DataResponse($callback()); }
		catch (DoesNotExistException|\OCA\ProofingGallery\Exception\AuthorizationException) { return new DataResponse(['message' => 'Gallery or resource not found'], Http::STATUS_NOT_FOUND); }
		catch (\InvalidArgumentException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
		catch (\OCP\Calendar\Exceptions\CalendarException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_BAD_GATEWAY); }
		catch (\OCP\Talk\Exceptions\NoBackendException $exception) { return new DataResponse(['message' => 'Talk is unavailable'], Http::STATUS_SERVICE_UNAVAILABLE); }
	}

	private function userId(): string { return $this->session->getUser()?->getUID() ?? throw new \RuntimeException('Authentication required'); }
}
