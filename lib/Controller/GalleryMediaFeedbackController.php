<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\MediaFeedbackRepository;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class GalleryMediaFeedbackController extends Controller {
	private const MAX_FILES = 200;

	public function __construct(IRequest $request, private GalleryAccessService $access, private MediaFeedbackRepository $feedback, private IUserSession $session) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/media/feedback')]
	public function index(int $id, string $fileIds = ''): DataResponse {
		if (strlen($fileIds) > 4400 || preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', $fileIds) !== 1) {
			return new DataResponse(['message' => 'Specify up to 200 media IDs'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$requested = array_values(array_unique(array_map('intval', explode(',', $fileIds))));
		if (count($requested) > self::MAX_FILES) return new DataResponse(['message' => 'Specify up to 200 media IDs'], Http::STATUS_UNPROCESSABLE_ENTITY);
		$user = $this->session->getUser();
		if ($user === null) return new DataResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		try {
			$this->access->view($user->getUID(), $id);
		} catch (DoesNotExistException|AuthorizationException) {
			return new DataResponse(['message' => 'Gallery not found'], Http::STATUS_NOT_FOUND);
		}
		// Counts are keyed by the requested ids only, so foreign file ids reveal nothing beyond zeros.
		$response = new DataResponse(['items' => $this->feedback->counts($id, $requested)]);
		$response->addHeader('Cache-Control', 'private, no-store');
		return $response;
	}
}
