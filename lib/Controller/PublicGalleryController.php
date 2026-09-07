<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
use OCA\ProofingGallery\Http\RangedStreamResponse;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\PublicGalleryDataService;
use OCA\ProofingGallery\Service\PublicShareContextResolver;
use OCA\ProofingGallery\Service\WatermarkPreviewService;
use OCA\ProofingGallery\Service\VideoTranscodeService;
use OCA\ProofingGallery\Service\CollectionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\ZipResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ISession;

final class PublicGalleryController extends ResolvedPublicShareController {
	public function __construct(
		IRequest $request,
		ISession $session,
		PublicShareContextResolver $contextResolver,
		private \OCA\ProofingGallery\Service\FolderService $folders,
		private IPreview $preview,
		private WatermarkPreviewService $watermarks,
		private \OCA\ProofingGallery\Service\DesignAssetService $designAssets,
		private PublicGalleryDataService $galleryData,
		private CollectionService $collections,
		private \OCA\ProofingGallery\Service\PublicMediaResolver $publicMedia,
		private PolicyService $policies,
		private \OCA\ProofingGallery\Service\CapabilityPolicyService $capabilities,
		private \OCA\ProofingGallery\Service\BrandingAssetService $branding,
		private \OCA\ProofingGallery\Service\ShareAuditService $shareAudit,
		private VideoTranscodeService $videoTranscodes,
		private \OCA\ProofingGallery\Service\PublicGalleryDownloadService $galleryDownloads,
		private \OCA\ProofingGallery\Service\WebJpegDerivativeService $webJpegs,
	) {
		parent::__construct($request, $session, $contextResolver);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/gallery')]
	public function gallery(
		int $limit = 48,
		int $offset = 0,
		string $path = '',
		string $search = '',
		string $sortBy = '',
		string $sortDirection = '',
		string $groupBy = '',
		?string $cursor = null,
		?int $page = null,
		?int $focusId = null,
	): JSONResponse {
		try {
			$result = $this->galleryData->page($this->publicContext(), new PublicGalleryQuery(
				$limit, $offset, $path, $search, $sortBy, $sortDirection, $groupBy, $cursor, $page, $focusId,
			));
			$this->shareAudit->record($this->resolvedPublicLink(), 'view');
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/media/{fileId}/preview')]
	public function preview(int $fileId, int $x = 1200, int $y = 1200, string $mode = 'cover'): DataDisplayResponse {
		$file = $this->fileInShare($fileId);
		if (str_starts_with($file->getMimeType(), 'video/')) {
			$this->videoTranscodes->request($this->resolvedGallery()->getOwnerUid(), $file);
			$poster = $this->videoTranscodes->derivative($this->resolvedGallery()->getOwnerUid(), $file, true);
			if ($poster !== null) return new DataDisplayResponse($poster->getContent(), Http::STATUS_OK, [
				'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400, immutable', 'ETag' => '"' . $poster->getETag() . '"',
			]);
		}
		return $this->previewResponse($file, $x, $y, true, $mode);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/media/{fileId}/stream')]
	public function mediaStream(int $fileId): Response {
		$file = $this->fileInShare($fileId);
		if (str_starts_with($file->getMimeType(), 'video/')) {
			$state = $this->videoTranscodes->request($this->resolvedGallery()->getOwnerUid(), $file);
			$derivative = $this->videoTranscodes->derivative($this->resolvedGallery()->getOwnerUid(), $file);
			if ($derivative !== null) return $this->streamResponse(
				(int)$derivative->getSize(), 'video/mp4', $derivative->getETag(), static fn () => $derivative->read(),
			);
			if (!$state['playable']) return new DataDisplayResponse('', Http::STATUS_ACCEPTED, [
				'Retry-After' => '5', 'Cache-Control' => 'private, no-store', 'X-Proofing-Video-State' => $state['state'],
			]);
		}
		return $this->streamResponse(
			(int)$file->getSize(), $file->getMimeType(), $file->getEtag(), static fn () => $file->fopen('rb'),
		);
	}

	/** @param callable(): mixed $open */
	private function streamResponse(int $size, string $mimeType, string $etag, callable $open): Response {
		return new RangedStreamResponse($this->request, $open, $size, $mimeType, $etag);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/media/{fileId}/download')]
	public function download(int $fileId, string $preset = 'original', bool $watermark = false): Response {
		if (!$this->downloadAllowed('individual')) {
			$this->shareAudit->record($this->resolvedPublicLink(), 'download', fileId: $fileId, outcome: 'denied', reasonCode: 'policy');
			return new DataDisplayResponse('', Http::STATUS_FORBIDDEN);
		}
		$file = $this->fileInShare($fileId);
		$this->shareAudit->record($this->resolvedPublicLink(), 'download', fileId: $fileId);
		if ($preset !== 'original') {
			try {
				$derivative = $this->webJpegs->derivative($file, $preset, $watermark, $this->publicContext()->settings->presentation, $this->resolvedGallery()->getOwnerUid());
				return new DataDisplayResponse($derivative->getContent(), Http::STATUS_OK, [
					'Content-Type' => 'image/jpeg',
					'Content-Disposition' => 'attachment; filename="' . addcslashes($this->webJpegs->filename($file->getName()), '"\\') . '"',
					'Content-Length' => (string)$derivative->getSize(), 'Cache-Control' => 'private, no-store',
					'ETag' => '"' . $derivative->getETag() . '"', 'X-Proofing-Download-Preset' => $preset,
				]);
			} catch (\InvalidArgumentException|\RuntimeException) {
				return new DataDisplayResponse('', Http::STATUS_UNPROCESSABLE_ENTITY);
			}
		}
		return new RangedStreamResponse(
			$this->request,
			static fn () => $file->fopen('rb'),
			(int)$file->getSize(),
			$file->getMimeType(),
			$file->getEtag(),
			$file->getName(),
			'private, no-store',
		);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/download/selection')]
	public function downloadSelection(string $fileIds, string $preset = 'original', bool $watermark = false): Response {
		if (!$this->downloadAllowed('selection')) {
			return new DataDisplayResponse('', Http::STATUS_FORBIDDEN);
		}
		$files = $this->selectedFiles($fileIds);
		if ($files === []) {
			return new DataDisplayResponse('', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$filename = preg_replace('/[^a-z0-9._-]+/i', '-', $this->resolvedGallery()->getTitle()) ?: 'gallery';
		$archive = new ZipResponse($this->request, trim($filename, '-') . '-selection.zip');
		if ($preset !== 'original' && !in_array($preset, $this->webJpegs->presets(), true)) return new DataDisplayResponse('', Http::STATUS_UNPROCESSABLE_ENTITY);
		foreach ($files as $file) {
			$name = $this->resolvedGallery()->getSourceType() === 'collection'
				? $this->collections->downloadPath($this->resolvedGallery(), $file)
				: $file->getName();
			try {
				$download = $preset === 'original' ? $file : $this->webJpegs->derivative($file, $preset, $watermark, $this->publicContext()->settings->presentation, $this->resolvedGallery()->getOwnerUid());
			} catch (\InvalidArgumentException|\RuntimeException) {
				return new DataDisplayResponse('', Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			if ($preset !== 'original') {
				$directory = pathinfo($name, PATHINFO_DIRNAME);
				$name = ($directory !== '.' ? $directory . '/' : '') . $this->webJpegs->filename(basename($name));
			}
			$stream = $preset === 'original' ? $file->fopen('rb') : $download->read();
			if (!is_resource($stream)) return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
			$archive->addResource($stream, $name, (int)$download->getSize(), (int)$file->getMTime());
		}
		$this->shareAudit->record($this->resolvedPublicLink(), 'export');
		return $archive;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/download/gallery/status')]
	public function galleryDownloadStatus(): JSONResponse {
		if (!$this->downloadAllowed('gallery')) return new JSONResponse([], Http::STATUS_FORBIDDEN);
		$status = $this->galleryDownloads->inspect($this->publicContext());
		unset($status['entries']);
		return new JSONResponse($status);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 12, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/download/gallery')]
	public function downloadGallery(): Response {
		if (!$this->downloadAllowed('gallery')) return new DataDisplayResponse('', Http::STATUS_FORBIDDEN);
		$result = $this->galleryDownloads->inspect($this->publicContext());
		if (!$result['available']) {
			$status = in_array($result['reason'], ['too_many_files', 'too_large'], true)
				? Http::STATUS_REQUEST_ENTITY_TOO_LARGE
				: ($result['reason'] === 'index_incomplete' ? Http::STATUS_CONFLICT : Http::STATUS_UNPROCESSABLE_ENTITY);
			return new DataDisplayResponse('', $status);
		}
		$filename = preg_replace('/[^a-z0-9._-]+/i', '-', $this->resolvedGallery()->getTitle()) ?: 'gallery';
		$archive = new ZipResponse($this->request, trim($filename, '-') . '.zip');
		foreach ($result['entries'] as $entry) {
			$stream = $entry['file']->fopen('rb');
			if (!is_resource($stream)) return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
			$archive->addResource($stream, $entry['path'], (int)$entry['file']->getSize(), (int)$entry['file']->getMTime());
		}
		$this->shareAudit->record($this->resolvedPublicLink(), 'export');
		return $archive;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/contact-sheet')]
	public function contactSheet(string $fileIds): DataDisplayResponse {
		if (!$this->downloadAllowed('contactSheet')) {
			return new DataDisplayResponse('', Http::STATUS_FORBIDDEN);
		}
		$files = array_values(array_filter(
			$this->selectedFiles($fileIds),
			static fn (File $file): bool => str_starts_with($file->getMimeType(), 'image/'),
		));
		if ($files === []) {
			return new DataDisplayResponse('', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$cards = '';
		foreach ($files as $file) {
			$src = htmlspecialchars(sprintf(
				'../media/%d/preview?x=700&y=500',
				$file->getId(),
			), ENT_QUOTES);
			$name = htmlspecialchars($file->getName(), ENT_QUOTES);
			$cards .= '<figure><img src="' . $src . '" alt=""><figcaption>' . $name . '</figcaption></figure>';
		}
		$title = htmlspecialchars($this->resolvedGallery()->getTitle(), ENT_QUOTES);
		$html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title>'
			. '<style>@page{size:A4;margin:12mm}body{font:12px system-ui;color:#111}h1{font-size:20px}'
			. 'main{display:grid;grid-template-columns:repeat(3,1fr);gap:8mm 5mm}figure{margin:0;break-inside:avoid}'
			. 'img{display:block;width:100%;aspect-ratio:4/3;object-fit:contain;background:#eee}figcaption{margin-top:4px;overflow-wrap:anywhere}'
			. '@media screen{body{max-width:1100px;margin:30px auto;padding:20px}}</style></head>'
			. '<body><h1>' . $title . '</h1><main>' . $cards . '</main><script>window.addEventListener("load",()=>window.print())</script></body></html>';
		return new DataDisplayResponse($html, Http::STATUS_OK, [
			'Content-Type' => 'text/html; charset=utf-8',
			'Cache-Control' => 'private, no-store',
			'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'",
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/asset/{kind}')]
	public function asset(string $kind, int $x = 1800, int $y = 1000): DataDisplayResponse {
		if (!in_array($kind, ['logo', 'hero'], true)) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$presentation = $this->publicContext()->settings->presentation;
		$fileId = $kind === 'hero' ? $presentation->heroFileId : ($presentation->logoMode === 'gallery' ? $presentation->logoFileId : null);
		if ($kind === 'logo' && $presentation->logoMode === 'upload' && $presentation->logoAssetId !== null) {
			try {
				$asset = $this->designAssets->owned($this->resolvedGallery()->getOwnerUid(), $presentation->logoAssetId, 'logo');
				return new DataDisplayResponse($this->designAssets->content($asset), Http::STATUS_OK, [
					'Content-Type' => $asset->getMimeType(), 'Cache-Control' => 'private, max-age=86400, immutable', 'X-Content-Type-Options' => 'nosniff',
				]);
			} catch (\Throwable) { return new DataDisplayResponse('', Http::STATUS_NOT_FOUND); }
		}
		if ($kind === 'logo' && $presentation->logoMode === 'inherit' && $presentation->instanceLogoAssetId !== null) {
			try {
				$asset = $this->branding->get($presentation->instanceLogoAssetId);
				return new DataDisplayResponse($asset->getContent(), Http::STATUS_OK, [
					'Content-Type' => $this->branding->mimeType($presentation->instanceLogoAssetId),
					'Cache-Control' => 'private, max-age=86400, immutable',
				]);
			} catch (\OCP\Files\NotFoundException) {
				return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
			}
		}
		if ($fileId === null) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		try {
			$gallery = $this->resolvedGallery();
			$file = $gallery->getSourceType() === 'collection'
				? $this->collections->resolveMedia($gallery, $fileId)
				: $this->folders->resolveMedia($gallery->getOwnerUid(), $gallery->getFolderId(), $fileId);
			if (str_starts_with($file->getMimeType(), 'image/')) return $this->previewResponse($file, $x, $y, false);
		} catch (\OCA\ProofingGallery\Exception\FolderAccessException|\OCP\Files\NotFoundException) {
		}
		return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
	}

	private function resolvedGallery(): Gallery {
		return $this->publicContext()->gallery;
	}

	private function resolvedPublicLink(): PublicLink {
		return $this->publicContext()->link;
	}

	private function fileInShare(int $fileId): File {
		return $this->publicMedia->resolve($this->publicContext(), $fileId);
	}

	private function previewResponse(
		File $file,
		int $x,
		int $y,
		bool $applyWatermark = true,
		string $mode = 'cover',
	): DataDisplayResponse {
		$x = max(64, min(2400, $x));
		$y = max(64, min(2400, $y));
		if (!in_array($mode, ['cover', 'fit'], true)) {
			return new DataDisplayResponse('', Http::STATUS_BAD_REQUEST);
		}
		try {
			$appearance = $this->publicContext()->settings->presentation;
			if ($applyWatermark) {
				$derivative = $this->watermarks->render($file, $x, $y, $appearance, $this->resolvedGallery()->getOwnerUid(), $mode);
				return new DataDisplayResponse($derivative['content'], Http::STATUS_OK, [
					'Content-Type' => $derivative['mimeType'],
					'Cache-Control' => 'private, max-age=86400, immutable',
					'ETag' => '"' . $derivative['etag'] . '"',
					'X-Proofing-Derivative-Cache' => $derivative['cached'] ? 'hit' : 'miss',
				]);
			}
			$preview = $this->preview->getPreview(
				$file,
				$x,
				$y,
				$mode === 'cover',
				$mode === 'cover' ? IPreview::MODE_COVER : IPreview::MODE_FILL,
			);
			return new DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
				'Content-Type' => $preview->getMimeType(),
				'Cache-Control' => 'private, max-age=3600',
				'ETag' => '"' . $file->getEtag() . '"',
			]);
		} catch (\OCP\Files\NotFoundException|\InvalidArgumentException) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	private function downloadAllowed(string $kind): bool {
		if (!$this->capabilities->feature('downloads')) return false;
		$settings = $this->publicContext()->settings;
		$scope = $settings->delivery->downloadScope->restrict($this->publicContext()->policy->downloadScope);
		return match ($kind) {
			'individual' => !$this->publicContext()->share->getHideDownload() && $scope->allowsIndividual(),
			'selection' => $scope->allowsSelection(),
			'gallery' => !$this->publicContext()->share->getHideDownload() && $scope->allowsGallery(),
			'contactSheet' => $settings->delivery->contactSheet && $scope->allowsSelection(),
			default => false,
		};
	}

	/** @return list<File> */
	private function selectedFiles(string $fileIds): array {
		$ids = array_values(array_unique(array_filter(
			array_map('intval', explode(',', $fileIds)),
			static fn (int $id): bool => $id > 0,
		)));
		if (count($ids) > $this->policies->get('maxSelectionFiles')) {
			throw new \InvalidArgumentException('The selection contains too many files');
		}
		$files = [];
		$totalSize = 0;
		foreach ($ids as $id) {
			$file = $this->fileInShare($id);
			$totalSize += (int)$file->getSize();
			if ($totalSize > $this->policies->get('maxSelectionBytes')) {
				throw new \InvalidArgumentException('Selection is too large');
			}
			$files[] = $file;
		}
		return $files;
	}
}
