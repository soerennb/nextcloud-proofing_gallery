<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Controller;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Http\TemporaryFileResponse;
use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\PublicGalleryDataService;
use OCA\ProofingGallery\Service\WatermarkPreviewService;
use OCA\ProofingGallery\Service\CollectionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\PublicShareController;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ISession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

final class PublicGalleryController extends PublicShareController {
	private ?IShare $share = null;
	private ?Gallery $gallery = null;

	public function __construct(
		IRequest $request,
		ISession $session,
		private IManager $shareManager,
		private GalleryMapper $galleries,
		private IRootFolder $rootFolder,
		private IPreview $preview,
		private WatermarkPreviewService $watermarks,
		private PublicGalleryDataService $galleryData,
		private CollectionService $collections,
		private PolicyService $policies,
	) {
		parent::__construct(Application::APP_ID, $request, $session);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/gallery')]
	public function gallery(
		int $limit = 60,
		int $offset = 0,
		string $path = '',
		string $search = '',
		string $sortBy = '',
		string $sortDirection = '',
		string $groupBy = '',
	): JSONResponse {
		try {
			return new JSONResponse($this->galleryData->page(
				$this->resolvedGallery(),
				$this->folder(),
				$limit,
				$offset,
				$path,
				$search,
				$sortBy,
				$sortDirection,
				$groupBy,
			));
		} catch (\InvalidArgumentException $exception) {
			return new JSONResponse(['message' => $exception->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/media/{fileId}/preview')]
	public function preview(int $fileId, int $x = 1200, int $y = 1200, string $mode = 'cover'): DataDisplayResponse {
		$file = $this->fileInShare($fileId);
		return $this->previewResponse($file, $x, $y, true, $mode);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/media/{fileId}/stream')]
	public function mediaStream(int $fileId): DataDisplayResponse {
		$file = $this->fileInShare($fileId);
		$size = (int)$file->getSize();
		$start = 0;
		$end = max(0, $size - 1);
		$status = Http::STATUS_OK;
		$range = $this->request->getHeader('Range');
		if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) === 1) {
			if ($matches[1] === '' && $matches[2] !== '') {
				$start = max(0, $size - (int)$matches[2]);
			} else {
				$start = (int)$matches[1];
			}
			if ($matches[2] !== '' && $matches[1] !== '') {
				$end = min($end, (int)$matches[2]);
			}
			if ($start > $end || $start >= $size) {
				return new DataDisplayResponse('', Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, [
					'Content-Range' => 'bytes */' . $size,
				]);
			}
			$status = Http::STATUS_PARTIAL_CONTENT;
		}
		$length = $end - $start + 1;

		$stream = $file->fopen('rb');
		if (!is_resource($stream) || fseek($stream, $start) !== 0) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$content = stream_get_contents($stream, $length);
		fclose($stream);
		if ($content === false) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}

		$headers = [
			'Accept-Ranges' => 'bytes',
			'Content-Length' => (string)$length,
			'Content-Type' => $file->getMimeType(),
			'Cache-Control' => 'private, max-age=3600',
			'ETag' => '"' . $file->getEtag() . '"',
		];
		if ($status === Http::STATUS_PARTIAL_CONTENT) {
			$headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
		}
		return new DataDisplayResponse($content, $status, $headers);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/media/{fileId}/download')]
	public function download(int $fileId): Response {
		if (!$this->downloadAllowed('individual')) {
			return new DataDisplayResponse('', Http::STATUS_FORBIDDEN);
		}
		$file = $this->fileInShare($fileId);
		return new DataDownloadResponse(
			$file->getContent(),
			$file->getName(),
			$file->getMimeType(),
			headers: ['Cache-Control' => 'private, no-store'],
		);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/{token}/download/selection')]
	public function downloadSelection(string $fileIds): Response {
		if (!$this->downloadAllowed('selection')) {
			return new DataDisplayResponse('', Http::STATUS_FORBIDDEN);
		}
		$files = $this->selectedFiles($fileIds);
		if ($files === []) {
			return new DataDisplayResponse('', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$temporaryPath = tempnam(sys_get_temp_dir(), 'proofing-gallery-');
		if ($temporaryPath === false) {
			return new DataDisplayResponse('', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$archive = new \ZipArchive();
		if ($archive->open($temporaryPath, \ZipArchive::OVERWRITE) !== true) {
			@unlink($temporaryPath);
			return new DataDisplayResponse('', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		foreach ($files as $file) {
			$name = $this->resolvedGallery()->getSourceType() === 'collection'
				? $this->collections->downloadPath($this->resolvedGallery(), $file)
				: $file->getName();
			$archive->addFromString($name, $file->getContent());
		}
		$archive->close();
		$filename = preg_replace('/[^a-z0-9._-]+/i', '-', $this->resolvedGallery()->getTitle()) ?: 'gallery';
		return new TemporaryFileResponse($temporaryPath, trim($filename, '-') . '-selection.zip', 'application/zip');
	}

	#[PublicPage]
	#[NoCSRFRequired]
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
			. 'img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#eee}figcaption{margin-top:4px;overflow-wrap:anywhere}'
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
		$settings = GallerySettings::fromArray(json_decode(
			$this->resolvedGallery()->getSettings(),
			true,
			flags: JSON_THROW_ON_ERROR,
		))->jsonSerialize();
		$fileId = $settings['appearance'][$kind . 'FileId'];
		if (!is_int($fileId)) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		foreach ($this->rootFolder->getUserFolder($this->resolvedGallery()->getOwnerUid())->getById($fileId) as $node) {
			if ($node instanceof File && str_starts_with($node->getMimeType(), 'image/') && $node->isReadable()) {
				return $this->previewResponse($node, $x, $y, false);
			}
		}
		return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
	}

	public function isValidToken(): bool {
		try {
			$this->share = $this->shareManager->getShareByToken($this->getToken());
			$this->gallery = $this->galleries->findByShareToken($this->getToken());
			return $this->share->getNodeId() === $this->gallery->getFolderId()
				&& $this->share->getNode() instanceof Folder;
		} catch (ShareNotFound|DoesNotExistException) {
			return false;
		}
	}

	protected function isPasswordProtected(): bool {
		return $this->share?->getPassword() !== null;
	}

	protected function getPasswordHash(): ?string {
		return $this->share?->getPassword();
	}

	private function resolvedGallery(): Gallery {
		if ($this->gallery === null) {
			throw new \RuntimeException('Public gallery was not resolved');
		}
		return $this->gallery;
	}

	private function folder(): Folder {
		$node = $this->share?->getNode();
		if (!$node instanceof Folder) {
			throw new \RuntimeException('Public gallery folder was not resolved');
		}
		return $node;
	}

	private function fileInShare(int $fileId): File {
		if ($this->resolvedGallery()->getSourceType() === 'collection') {
			try {
				return $this->collections->resolveMedia($this->resolvedGallery(), $fileId);
			} catch (\Throwable) {
				throw new \OCP\Files\NotFoundException('Media file not found');
			}
		}
		foreach ($this->folder()->getById($fileId) as $node) {
			if ($node instanceof File && $this->folder()->isSubNode($node) && $this->isSupported($node)) {
				return $node;
			}
		}
		throw new \OCP\Files\NotFoundException('Media file not found');
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
			$appearance = GallerySettings::fromArray(json_decode(
				$this->resolvedGallery()->getSettings(),
				true,
				flags: JSON_THROW_ON_ERROR,
			))->appearance;
			if ($applyWatermark && $appearance['watermarkText'] !== '') {
				$watermarked = $this->watermarks->render(
					$file,
					$x,
					$y,
					$appearance['watermarkText'],
					$appearance['watermarkOpacity'],
					$mode,
				);
				return new DataDisplayResponse($watermarked['content'], Http::STATUS_OK, [
					'Content-Type' => $watermarked['mimeType'],
					'Cache-Control' => 'private, max-age=86400, immutable',
					'ETag' => '"' . $watermarked['etag'] . '"',
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

	private function isSupported(File $file): bool {
		return str_starts_with($file->getMimeType(), 'image/')
			|| in_array($file->getMimeType(), ['video/mp4', 'video/webm'], true);
	}

	private function downloadAllowed(string $kind): bool {
		$settings = GallerySettings::fromArray(json_decode(
			$this->resolvedGallery()->getSettings(),
			true,
			flags: JSON_THROW_ON_ERROR,
		));
		$scope = $settings->delivery['downloadScope'];
		return match ($kind) {
			'individual' => $this->share?->getHideDownload() !== true && in_array($scope, ['individual', 'all'], true),
			'selection' => in_array($scope, ['selection', 'all'], true),
			'contactSheet' => $settings->delivery['contactSheet'] && in_array($scope, ['selection', 'all'], true),
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
