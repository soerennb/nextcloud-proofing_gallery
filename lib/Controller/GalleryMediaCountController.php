<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\GalleryAccessService;
use OCA\ProofingGallery\Service\GalleryMediaCountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class GalleryMediaCountController extends Controller {
	public function __construct(IRequest $request, private GalleryAccessService $access, private GalleryMediaCountRepository $counts, private GalleryMediaCountService $service, private IUserSession $session) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/media-counts')]
	public function index(string $ids = ''): DataResponse {
		if (strlen($ids) > 2200 || preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', $ids) !== 1) return new DataResponse(['message' => 'Specify up to 100 gallery IDs'], Http::STATUS_UNPROCESSABLE_ENTITY);
		$requested = array_values(array_unique(array_map('intval', explode(',', $ids))));
		if (count($requested) > 100) return new DataResponse(['message' => 'Specify up to 100 gallery IDs'], Http::STATUS_UNPROCESSABLE_ENTITY);
		$user = $this->session->getUser();
		if ($user === null) return new DataResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		$allowed = [];
		foreach ($requested as $id) {
			try { $this->access->view($user->getUID(), $id); $allowed[] = $id; }
			catch (DoesNotExistException|AuthorizationException) {}
		}
		$rows = $this->counts->findMany($allowed);
		$items = [];
		foreach ($allowed as $id) {
			$row = $rows[$id] ?? null;
			if ($row === null || in_array($row['state'], ['pending', 'updating', 'error'], true)) $this->service->queue($id);
			$items[] = ['id' => $id, 'mediaSummary' => GalleryMediaCountService::present($row)];
		}
		$response = new DataResponse(['items' => $items]);
		$response->addHeader('Cache-Control', 'private, no-store');
		return $response;
	}
}
