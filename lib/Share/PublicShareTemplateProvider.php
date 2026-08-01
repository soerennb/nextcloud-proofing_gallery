<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Share;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\PublicGalleryDataService;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Share\IPublicShareTemplateProvider;
use OCP\Share\IShare;
use OCP\Util;

final class PublicShareTemplateProvider implements IPublicShareTemplateProvider {
	public function __construct(
		private IInitialState $initialState,
		private PublicGalleryDataService $galleryData,
		private IURLGenerator $urlGenerator,
		private \OCA\ProofingGallery\Service\PublicShareContextResolver $contexts,
	) {
	}

	public function shouldRespond(IShare $share): bool {
		return $this->contexts->tryResolveShare($share) !== null;
	}

	public function renderPage(IShare $share, string $token, string $path): TemplateResponse {
		$context = $this->contexts->resolveShare($share);
		$link = $context->link;
		$gallery = $context->gallery;
		$node = $context->root;
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
