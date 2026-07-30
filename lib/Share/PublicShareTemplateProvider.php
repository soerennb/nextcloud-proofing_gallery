<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Share;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Share\IPublicShareTemplateProvider;
use OCP\Share\IShare;
use OCP\Util;

final class PublicShareTemplateProvider implements IPublicShareTemplateProvider {
	public function __construct(
		private GalleryMapper $galleries,
		private IInitialState $initialState,
	) {
	}

	public function shouldRespond(IShare $share): bool {
		try {
			$gallery = $this->galleries->findByShareToken($share->getToken());
			return $gallery->getFolderId() === $share->getNodeId();
		} catch (DoesNotExistException) {
			return false;
		}
	}

	public function renderPage(IShare $share, string $token, string $path): TemplateResponse {
		$gallery = $this->galleries->findByShareToken($token);
		$this->initialState->provideInitialState('public-gallery', [
			'id' => $gallery->getId(),
			'title' => $gallery->getTitle(),
			'settings' => GallerySettings::fromArray(
				json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR),
			),
			'token' => $token,
			'path' => $path,
		]);
		Util::addScript(Application::APP_ID, 'proofing_gallery-public');
		Util::addStyle(Application::APP_ID, 'proofing_gallery-public');

		$response = new PublicTemplateResponse(Application::APP_ID, 'public');
		$response->setHeaderTitle($gallery->getTitle());
		$response->setParams(['pageTitle' => $gallery->getTitle()]);
		return $response;
	}
}
