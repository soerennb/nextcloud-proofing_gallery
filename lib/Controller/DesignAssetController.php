<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\DesignAssetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

final class DesignAssetController extends Controller {
	public function __construct(IRequest $request, private DesignAssetService $assets, private IUserSession $userSession) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/design-assets')]
	public function index(?string $kind = null): DataResponse {
		try {
			return new DataResponse(['items' => $this->assets->listOwned($this->userId(), $kind)]);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/design-assets')]
	public function create(string $kind): DataResponse {
		try {
			$upload = $this->request->getUploadedFile('asset');
			if (!is_array($upload)) throw new \InvalidArgumentException('A valid design asset upload is required');
			return new DataResponse($this->assets->store($this->userId(), $kind, $upload), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/design-assets/{assetId}')]
	public function show(string $assetId): DataDisplayResponse {
		try {
			$asset = $this->assets->owned($this->userId(), $assetId);
			return new DataDisplayResponse($this->assets->content($asset), Http::STATUS_OK, [
				'Content-Type' => $asset->getMimeType(), 'Cache-Control' => 'private, max-age=3600',
				'X-Content-Type-Options' => 'nosniff',
			]);
		} catch (NotFoundException|\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/design-assets/{assetId}')]
	public function delete(string $assetId): DataResponse {
		try {
			$this->assets->deleteOwned($this->userId(), $assetId);
			return new DataResponse([]);
		} catch (NotFoundException|\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Design asset not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
