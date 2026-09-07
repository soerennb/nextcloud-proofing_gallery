<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\Settings\PresentationSettings;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IPreview;

/** Creates metadata-free, bounded JPEGs without ever modifying source files. */
final class WebJpegDerivativeService {
	/** @var array<string, array{size: int, quality: int}> */
	private const PRESETS = [
		'web-2048' => ['size' => 2048, 'quality' => 85],
		'web-1600' => ['size' => 1600, 'quality' => 82],
	];

	public function __construct(
		private IPreview $previews,
		private IAppData $appData,
		private WatermarkPreviewService $watermarks,
	) {
	}

	/** @return list<string> */
	public function presets(): array {
		return array_keys(self::PRESETS);
	}

	public function derivative(File $source, string $preset, bool $watermark, PresentationSettings $settings, string $ownerUid): ISimpleFile {
		$config = self::PRESETS[$preset] ?? throw new \InvalidArgumentException('Unknown download preset');
		if (!str_starts_with($source->getMimeType(), 'image/')) throw new \InvalidArgumentException('Web JPEG downloads require an image');
		$settingsHash = $watermark ? hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)) : 'none';
		$key = hash('sha256', implode('|', ['web-jpeg-v1', (string)$source->getId(), $source->getEtag(), $preset, $watermark ? '1' : '0', $settingsHash]));
		$folder = $this->folder();
		$name = $key . '.jpg';
		if ($folder->fileExists($name)) return $folder->getFile($name);

		$size = $config['size'];
		$content = $watermark
			? $this->watermarks->render($source, $size, $size, $settings, $ownerUid, 'fit')['content']
			: $this->previews->getPreview($source, $size, $size, false, IPreview::MODE_FILL)->getContent();
		$image = @imagecreatefromstring($content);
		if (!$image instanceof \GdImage) throw new \InvalidArgumentException('Image cannot be converted to a web JPEG');
		$image = $this->bound($image, $size);
		$jpeg = imagecreatetruecolor(imagesx($image), imagesy($image));
		$white = imagecolorallocate($jpeg, 255, 255, 255);
		imagefill($jpeg, 0, 0, $white);
		imagealphablending($jpeg, true);
		imagecopy($jpeg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
		imagedestroy($image);
		imageinterlace($jpeg, true);
		ob_start();
		$encoded = imagejpeg($jpeg, null, $config['quality']);
		$output = (string)ob_get_clean();
		imagedestroy($jpeg);
		if (!$encoded || $output === '') throw new \RuntimeException('Web JPEG generation failed');
		try {
			return $folder->newFile($name, $output);
		} catch (\OCP\Files\NotPermittedException) {
			// A concurrent request may have populated the content-addressed entry.
			return $folder->getFile($name);
		}
	}

	public function filename(string $sourceName): string {
		$base = pathinfo($sourceName, PATHINFO_FILENAME);
		$base = trim(preg_replace('/[^a-z0-9._-]+/i', '-', $base) ?? '', '-');
		return ($base !== '' ? $base : 'photo') . '.jpg';
	}

	private function bound(\GdImage $source, int $maximum): \GdImage {
		$width = imagesx($source); $height = imagesy($source);
		$scale = min(1, $maximum / max($width, $height));
		if ($scale >= 1) return $source;
		$target = imagescale($source, max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)), IMG_BICUBIC_FIXED);
		if (!$target instanceof \GdImage) {
			imagedestroy($source);
			throw new \RuntimeException('Web JPEG resize failed');
		}
		imagedestroy($source);
		return $target;
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder('web-jpeg-downloads');
		} catch (\OCP\Files\NotFoundException) {
			return $this->appData->newFolder('web-jpeg-downloads');
		}
	}
}
