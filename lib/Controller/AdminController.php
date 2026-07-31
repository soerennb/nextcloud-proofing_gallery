<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use InvalidArgumentException;
use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Service\HealthService;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\CollectionAnchorReconciler;
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
	) {
		parent::__construct(Application::APP_ID, $request);
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
			]);
			return new DataResponse([
				'policies' => $this->policies->all(),
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
}
