<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Domain\PublicLinkCapability;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCP\AppFramework\PublicShareController;
use OCP\IRequest;
use OCP\ISession;

abstract class ResolvedPublicShareController extends PublicShareController {
	private ?PublicShareContext $shareContext = null;

	public function __construct(
		IRequest $request,
		ISession $session,
		private PublicShareContextResolver $contextResolver,
		private PublicLinkCapability $requiredPermission = PublicLinkCapability::View,
	) {
		parent::__construct(Application::APP_ID, $request, $session);
	}

	final public function isValidToken(): bool {
		$this->shareContext = $this->contextResolver->tryResolve($this->getToken(), $this->requiredPermission);
		return $this->shareContext !== null;
	}

	final protected function isPasswordProtected(): bool {
		return $this->shareContext?->share->getPassword() !== null;
	}

	final protected function getPasswordHash(): ?string {
		return $this->shareContext?->share->getPassword();
	}

	final protected function publicContext(): PublicShareContext {
		return $this->shareContext ??= $this->contextResolver->resolve($this->getToken(), $this->requiredPermission);
	}

	final protected function guestSecret(Gallery $gallery): ?string {
		$scoped = $this->request->getCookie(GuestService::cookieName($gallery));
		return $scoped === null || $scoped === ''
			? $this->request->getCookie(GuestService::COOKIE_NAME)
			: $scoped;
	}
}
