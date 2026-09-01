<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Util;

final class PageController extends Controller {
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'index');
	}

	/**
	 * Bare same-origin document that hosts the design preview iframe. The
	 * public preview bundle is attached through the standard script pipeline
	 * so it receives the correct content security nonce.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/preview-frame')]
	public function previewFrame(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'proofing_gallery-public-preview');
		Util::addStyle(Application::APP_ID, 'proofing_gallery-public-preview');
		return new TemplateResponse(Application::APP_ID, 'preview-frame', [], TemplateResponse::RENDER_AS_BASE);
	}
}
