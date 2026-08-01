<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Dto\PublicShareContext;
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
		private string $requiredPermission = 'view',
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
}
