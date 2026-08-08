<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCA\ProofingGallery\Exception\PolicyViolationException;
use OCA\ProofingGallery\Service\FileIntegrationService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class FileIntegrationController extends OCSController {
	public function __construct(IRequest $request, private FileIntegrationService $files, private IUserSession $userSession) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/files/open/{fileId}')]
	public function open(int $fileId): DataResponse {
		try {
			return new DataResponse($this->files->resolve($this->userId(), $fileId));
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Folder not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/files/create/{fileId}')]
	public function create(int $fileId, ?string $title = null): DataResponse {
		try {
			return new DataResponse($this->files->create($this->userId(), $fileId, $title), Http::STATUS_CREATED);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Folder not found'], Http::STATUS_NOT_FOUND);
		} catch (PolicyViolationException $exception) {
			return new DataResponse(['code' => $exception->policyCode, 'message' => $exception->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException|FolderAccessException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
