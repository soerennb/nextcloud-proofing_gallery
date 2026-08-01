<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Share;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\PublicGalleryDataService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\Folder;
use OCP\IURLGenerator;
use OCP\Share\IPublicShareTemplateProvider;
use OCP\Share\IShare;
use OCP\Util;

final class PublicShareTemplateProvider implements IPublicShareTemplateProvider {
	public function __construct(
		private GalleryMapper $galleries,
		private PublicLinkMapper $publicLinks,
		private IInitialState $initialState,
		private PublicGalleryDataService $galleryData,
		private IURLGenerator $urlGenerator,
		private \OCA\ProofingGallery\Service\PublicLinkPolicyService $linkPolicies,
		private \OCA\ProofingGallery\Service\FolderService $folders,
	) {
	}

	public function shouldRespond(IShare $share): bool {
		try {
			$link = $this->publicLinks->findByToken($share->getToken());
			$gallery = $this->galleries->find($link->getGalleryId());
			$policy = $this->linkPolicies->validate(json_decode($link->getPolicy(), true, flags: JSON_THROW_ON_ERROR));
			$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
			$expected = $link->getStartPath() === '' ? $root : $root->get($link->getStartPath());
			return $expected instanceof Folder && $policy['view'] && $link->getStatus() === 'active' && $link->getRevokedAt() === null && $expected->getId() === $share->getNodeId();
		} catch (DoesNotExistException) {
			return false;
		}
	}

	public function renderPage(IShare $share, string $token, string $path): TemplateResponse {
		$link = $this->publicLinks->findByToken($token);
		$gallery = $this->galleries->find($link->getGalleryId());
		$node = $share->getNode();
		if (!$node instanceof Folder) {
			throw new \RuntimeException('Public gallery folder was not resolved');
		}
		$initialPage = $this->galleryData->page($gallery, $node, 60, 0, $path, link: $link, nativeRootIsScope: true);
		$this->initialState->provideInitialState('public-gallery', [
			'id' => $gallery->getId(),
			'title' => $gallery->getTitle(),
			'settings' => $initialPage['gallery']['settings'],
			'effectiveCapabilities' => $initialPage['gallery']['effectiveCapabilities'],
			'token' => $token,
			'path' => $path,
			'initialPage' => $initialPage,
		]);
		Util::addScript(Application::APP_ID, 'proofing_gallery-public');
		Util::addStyle(Application::APP_ID, 'proofing_gallery-public');

		$response = new PublicTemplateResponse(Application::APP_ID, 'public');
		$response->setHeaderTitle($gallery->getTitle());
		$heroImage = ($initialPage['gallery']['settings']['appearance']['heroFileId'] ?? null) !== null
			? $this->urlGenerator->linkToRouteAbsolute(
				'proofing_gallery.PublicGallery.asset',
				['token' => $token, 'kind' => 'hero'],
			)
			: null;
		$firstImage = null;
		foreach ($initialPage['items'] as $item) {
			if (!$item['folder'] && str_starts_with($item['mimeType'], 'image/')) {
				$firstImage = $item;
				break;
			}
		}
		$response->setParams([
			'pageTitle' => $gallery->getTitle(),
			'preloadImage' => $heroImage ?? ($firstImage === null
				? null
				: $this->urlGenerator->linkToRouteAbsolute(
					'proofing_gallery.PublicGallery.preview',
					['token' => $token, 'fileId' => $firstImage['id'], 'x' => 900, 'y' => 900, 'mode' => 'fit'],
				)),
		]);
		return $response;
	}
}
