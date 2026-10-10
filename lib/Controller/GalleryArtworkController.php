<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\GalleryArtworkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IPreview;

final class GalleryArtworkController extends Controller {
	public function __construct(
		IRequest $request,
		private GalleryService $galleries,
		private GalleryArtworkService $artwork,
		private IPreview $previews,
		private IUserSession $users,
		private \OCA\ProofingGallery\Service\FolderService $folders,
		private \OCA\ProofingGallery\Service\CollectionService $collections,
		private \OCA\ProofingGallery\Service\EventSetupService $events,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/galleries/{id}/artwork')]
	public function images(int $id, string $path = '', string $search = '', int $limit = 60, int $offset = 0, string $scope = ''): DataResponse {
		try {
			$user = $this->users->getUser();
			if ($user === null) throw new \OCP\Files\NotFoundException('Gallery not found');
			$gallery = $this->galleries->view($user->getUID(), $id);
			$limit = max(1, min(100, $limit));
			$offset = max(0, $offset);
			$search = mb_substr(trim($search), 0, 120);
			if ($gallery->getDeliveryMode() === 'event' && $scope !== '') return new DataResponse($this->events->designMedia($gallery, $scope, $search, $limit, $offset, true));
			if ($gallery->getSourceType() === 'folder') return new DataResponse($this->folders->listMedia(
				$gallery->getOwnerUid(), $gallery->getFolderId(), $limit, $offset, $path, $search, imagesOnly: true,
			));
			if ($path !== '') throw new \InvalidArgumentException('Collections do not contain folders');
			$items = array_values(array_filter($this->collections->availableItems($gallery), static fn (array $item): bool =>
				str_starts_with((string)$item['mimeType'], 'image/') && ($search === '' || mb_stripos((string)$item['name'], $search) !== false)));
			return new DataResponse(['items' => array_slice($items, $offset, $limit), 'total' => count($items), 'limit' => $limit, 'offset' => $offset]);
		} catch (\OCP\Files\NotFoundException|\OCA\ProofingGallery\Exception\FolderAccessException|\OCA\ProofingGallery\Exception\AuthorizationException|\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataResponse(['message' => 'Gallery or folder not found'], 404);
		} catch (\InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], 422);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/media/{id}/cover-preview')]
	public function coverPreview(int $id, int $x = 360, int $y = 204): DataDisplayResponse {
		try {
			$user = $this->users->getUser();
			if ($user === null) throw new \OCP\Files\NotFoundException('Gallery not found');
			$gallery = $this->galleries->view($user->getUID(), $id);
			$file = $this->artwork->cover($gallery);
			if ($file === null || !$this->previews->isAvailable($file)) throw new \OCP\Files\NotFoundException('Preview unavailable');
			$preview = $this->previews->getPreview($file, max(64, min(2400, $x)), max(64, min(2400, $y)), true, IPreview::MODE_COVER);
			return new DataDisplayResponse($preview->getContent(), 200, [
				'Content-Type' => $preview->getMimeType(), 'Cache-Control' => 'private, no-cache',
				'ETag' => '"' . hash('sha256', $file->getId() . ':' . $file->getEtag() . ':' . $x . ':' . $y) . '"',
			]);
		} catch (\OCP\Files\NotFoundException|\OCA\ProofingGallery\Exception\FolderAccessException|\OCA\ProofingGallery\Exception\AuthorizationException|\OCP\AppFramework\Db\DoesNotExistException) {
			return new DataDisplayResponse('', 404, ['Cache-Control' => 'private, no-store']);
		}
	}
}
