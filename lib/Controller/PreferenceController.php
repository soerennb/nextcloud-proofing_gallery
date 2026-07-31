<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\CapabilityPolicyService;
use OCA\ProofingGallery\Service\UserPreferenceService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\ProofingGallery\Service\FolderService;
use OCA\ProofingGallery\Db\PresetMapper;
use OCA\ProofingGallery\Exception\FolderAccessException;
use OCP\AppFramework\Db\DoesNotExistException;

final class PreferenceController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private UserPreferenceService $preferences,
		private CapabilityPolicyService $capabilities,
		private PolicyService $policies,
		private FolderService $folders,
		private PresetMapper $presets,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/user/preferences')]
	public function show(): DataResponse {
		$userId = $this->userId();
		return new DataResponse([
			'preferences' => $this->preferences->get($userId),
			'effectiveCapabilities' => $this->capabilities->effective(userId: $userId),
			'instanceDefaultPurpose' => $this->policies->instanceSettings()['workflow']['defaultPurpose'],
		]);
	}

	/** @param array<string, mixed> $preferences */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/user/preferences')]
	public function update(array $preferences): DataResponse {
		try {
			$userId = $this->userId();
			if (is_array($preferences['parentFolder'] ?? null)) {
				$this->folders->resolveFolder($userId, (int)($preferences['parentFolder']['id'] ?? 0));
			}
			if (($preferences['designPresetId'] ?? null) !== null) {
				$this->presets->findOwned((int)$preferences['designPresetId'], $userId);
			}
			return new DataResponse(['preferences' => $this->preferences->save($userId, $preferences)]);
		} catch (InvalidArgumentException|FolderAccessException|DoesNotExistException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Authenticated user required');
		return $user->getUID();
	}
}
