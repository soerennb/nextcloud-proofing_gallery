<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\CustomDomainService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

final class CustomDomainEntryController extends Controller {
	public function __construct(IRequest $request, private CustomDomainService $domains) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/domain')]
	public function entry(): Response {
		$mapping = $this->domains->resolve($this->request->getHeader('Host'));
		if ($mapping === null || !is_string($mapping['token'] ?? null)) return new DataResponse(['message' => 'Custom gallery domain not found'], Http::STATUS_NOT_FOUND);
		return new RedirectResponse('/s/' . rawurlencode($mapping['token']));
	}
}
