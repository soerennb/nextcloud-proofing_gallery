<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\CustomDomainService;
use OCA\ProofingGallery\Service\ScopedCursorCodec;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class CustomDomainAdminController extends Controller {
	public function __construct(IRequest $request, private CustomDomainService $domains, private ScopedCursorCodec $cursors) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/domains')]
	public function index(int $limit = 50, ?string $cursor = null, string $status = 'active', string $search = ''): DataResponse {
		try {
			return new DataResponse($this->domains->adminPage($limit, $cursor, $status, $search, $this->cursors));
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/domains/{id}/verify')]
	public function verify(int $id): DataResponse {
		try { return new DataResponse($this->domains->verify($id)); }
		catch (\InvalidArgumentException $exception) { return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY); }
	}

	#[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/domains/{id}')]
	public function revoke(int $id): DataResponse {
		try {
			$this->domains->revoke($id);
			return new DataResponse([], Http::STATUS_NO_CONTENT);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}
}
