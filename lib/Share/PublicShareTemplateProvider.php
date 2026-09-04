<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Share;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\PublicGalleryDataService;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
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
		private \OCA\ProofingGallery\Service\ReviewWorkflowService $reviews,
	) {
	}

	public function shouldRespond(IShare $share): bool {
		return $this->contexts->isManagedShare($share);
	}

	public function renderPage(IShare $share, string $token, string $path): TemplateResponse {
		$context = $this->contexts->tryResolveShare($share);
		if ($context === null) {
			$response = new PublicTemplateResponse(
				Application::APP_ID,
				'public-unavailable',
				status: \OCP\AppFramework\Http::STATUS_NOT_FOUND,
				headers: ['Cache-Control' => 'private, no-store'],
			);
			$response->setHeaderTitle('Gallery unavailable');
			return $response;
		}
		$gallery = $context->gallery;
		$initialPage = $this->galleryData->page($context, new PublicGalleryQuery(path: $path));
		$firstImage = null;
		foreach ($initialPage['items'] as $item) {
			if (!$item['folder'] && str_starts_with($item['mimeType'], 'image/')) {
				$firstImage = $item;
				break;
			}
		}
		$firstPaintImage = $firstImage === null ? null : $this->urlGenerator->linkToRouteAbsolute(
			'proofing_gallery.PublicGallery.preview',
			['token' => $token, 'fileId' => $firstImage['id'], 'x' => 900, 'y' => 900, 'mode' => 'fit'],
		);
		if ($firstPaintImage !== null) {
			Util::addHeader('link', [
				'rel' => 'preload',
				'as' => 'image',
				'href' => $firstPaintImage,
				'fetchpriority' => 'high',
			]);
		}
		$this->initialState->provideInitialState('public-gallery', [
			'id' => $gallery->getId(),
			'title' => $gallery->getTitle(),
			'deliveryMode' => $gallery->getDeliveryMode(),
			'settings' => $initialPage['gallery']['settings'],
			'effectiveCapabilities' => $initialPage['gallery']['effectiveCapabilities'],
			'token' => $token,
			'path' => $path,
			'initialPage' => $initialPage,
			'review' => $this->reviews->publicState($context->gallery, $context->link),
		]);
		Util::addScript(Application::APP_ID, 'proofing_gallery-public');
		Util::addStyle(Application::APP_ID, 'proofing_gallery-public');

		$response = new PublicTemplateResponse(Application::APP_ID, 'public');
		if ($firstPaintImage !== null) {
			$response->addHeader('Link', sprintf('<%s>; rel=preload; as=image', $firstPaintImage));
		}
		$response->setHeaderTitle($gallery->getTitle());
		$response->setParams([
			'pageTitle' => $gallery->getTitle(),
			'lcpPreviewUrl' => $firstPaintImage,
		]);
		return $response;
	}
}
