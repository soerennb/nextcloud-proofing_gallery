<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\HealthService;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\CollectionAnchorReconciler;
use OCA\ProofingGallery\Service\CoreSharingPolicyService;
use OCA\ProofingGallery\Service\SettingsRolloutService;
use OCA\ProofingGallery\Service\BrandingAssetService;
use OCA\ProofingGallery\Db\SemanticIndexRepository;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private PolicyService $policies,
		private HealthService $health,
		private CollectionAnchorReconciler $collectionAnchors,
		private CoreSharingPolicyService $coreSharing,
		private SettingsRolloutService $rollout,
		private BrandingAssetService $branding,
		private SemanticIndexRepository $semanticIndex,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/branding/logo')]
	public function uploadBrandLogo(): DataResponse {
		try {
			$previousId = $this->policies->instanceSettings()['branding']['logoAssetId'];
			$upload = $this->request->getUploadedFile('logo');
			if (!is_array($upload)) throw new InvalidArgumentException('A valid logo upload is required');
			$asset = $this->branding->store($upload);
			$settings = $this->policies->saveInstanceSettings(['branding' => ['logoAssetId' => $asset['id']]]);
			if (is_string($previousId)) $this->branding->delete($previousId);
			return new DataResponse(['asset' => $asset, 'branding' => $settings['branding']], Http::STATUS_CREATED);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/branding/logo')]
	public function deleteBrandLogo(): DataResponse {
		$previousId = $this->policies->instanceSettings()['branding']['logoAssetId'];
		$settings = $this->policies->saveInstanceSettings(['branding' => ['logoAssetId' => null]]);
		if (is_string($previousId)) $this->branding->delete($previousId);
		return new DataResponse(['branding' => $settings['branding']]);
	}

	/** @param list<int> $galleryIds
	 * @param list<string> $categories
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/settings/impact')]
	public function impact(array $galleryIds, array $categories): DataResponse {
		try {
			return new DataResponse($this->rollout->impact($galleryIds, $categories));
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param list<int> $galleryIds
	 * @param list<string> $categories
	 * @param array<int|string, int> $expectedRevisions
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/settings/apply')]
	public function apply(array $galleryIds, array $categories, array $expectedRevisions): DataResponse {
		try {
			$result = $this->rollout->apply($galleryIds, $categories, $expectedRevisions);
			return new DataResponse($result, $result['conflicts'] === [] ? Http::STATUS_OK : Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @param list<int> $galleryIds */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/publications/revoke')]
	public function revokePublications(array $galleryIds): DataResponse {
		try {
			return new DataResponse($this->rollout->revoke($galleryIds));
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/settings')]
	public function settings(): DataResponse {
		return new DataResponse([
			'instanceSettings' => $this->policies->instanceSettings(),
			'policies' => $this->policies->all(),
			'galleryDefaults' => $this->policies->galleryDefaults(),
			'coreSharing' => $this->coreSharing->status(),
			'health' => $this->health->status(),
		]);
	}

	/**
	 * @param array<string, mixed> $instanceSettings
	 * @param array<string, int> $policies
	 * @param array<string, mixed> $galleryDefaults
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/settings')]
	public function updateSettings(
		array $instanceSettings = [],
		array $policies = [],
		array $galleryDefaults = [],
	): DataResponse {
		try {
			if ($instanceSettings !== []) $this->policies->saveInstanceSettings($instanceSettings);
			if ($policies !== []) $this->policies->save(array_map('intval', $policies));
			if ($galleryDefaults !== []) $this->policies->saveGalleryDefaults($galleryDefaults);
			return $this->settings();
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/policies')]
	public function save(
		int $maxUploadMiB,
		int $maxSelectionFiles,
		int $maxSelectionMiB,
		int $eventRetentionDays,
		int $previewRetentionDays,
		int $pendingUploadRetentionHours,
		int $completedUploadRetentionDays,
		int $maxVersionsPerFile = 10,
		int $versionRetentionDays = 365,
		int $metadataMaxMiB = 64,
		int $metadataBatchSize = 100,
		int $xmpWritingEnabled = 1,
		int $maxIndexedMedia = 25000,
		int $maxPublicLinks = 10,
		int $shareAuditRetentionDays = 90,
		string $defaultPublicLocale = 'auto',
		string $defaultTheme = 'dark',
		string $defaultLayout = 'grid',
		string $defaultDownloadScope = 'none',
	): DataResponse {
		try {
			$this->policies->save([
				'maxUploadBytes' => $maxUploadMiB * 1024 * 1024,
				'maxSelectionFiles' => $maxSelectionFiles,
				'maxSelectionBytes' => $maxSelectionMiB * 1024 * 1024,
				'eventRetentionDays' => $eventRetentionDays,
				'previewRetentionDays' => $previewRetentionDays,
				'pendingUploadRetentionHours' => $pendingUploadRetentionHours,
				'completedUploadRetentionDays' => $completedUploadRetentionDays,
				'maxVersionsPerFile' => $maxVersionsPerFile,
				'versionRetentionDays' => $versionRetentionDays,
				'metadataMaxBytes' => $metadataMaxMiB * 1024 * 1024,
				'metadataBatchSize' => $metadataBatchSize,
				'xmpWritingEnabled' => $xmpWritingEnabled,
				'maxIndexedMedia' => $maxIndexedMedia,
				'maxPublicLinks' => $maxPublicLinks,
				'shareAuditRetentionDays' => $shareAuditRetentionDays,
			]);
			$this->policies->saveGalleryDefaults([
				'publicLocale' => $defaultPublicLocale,
				'presentation' => ['theme' => $defaultTheme, 'layout' => $defaultLayout],
				'delivery' => ['downloadScope' => $defaultDownloadScope],
			]);
			return new DataResponse([
				'policies' => $this->policies->all(),
				'galleryDefaults' => $this->policies->galleryDefaults(),
				'health' => $this->health->status(),
			]);
		} catch (InvalidArgumentException $exception) {
			return new DataResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/collection-anchors/reconcile')]
	public function reconcileCollectionAnchors(bool $dryRun = true): DataResponse {
		return new DataResponse($this->collectionAnchors->reconcile($dryRun));
	}

	#[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/semantic-index')]
	public function deleteSemanticIndex(): DataResponse {
		return new DataResponse(['deleted' => $this->semanticIndex->deleteAll()]);
	}
}
