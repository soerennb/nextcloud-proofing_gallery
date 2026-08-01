<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\IPreview;

/**
 * Produces derivative previews only. Source files are never opened for writing.
 */
final class WatermarkPreviewService {
	public function __construct(
		private IPreview $preview,
		private IAppData $appData,
	) {
	}

	/** @return array{content: string, mimeType: string, etag: string, cached: bool} */
	public function render(
		File $file,
		int $width,
		int $height,
		string $text,
		int $opacity,
		string $mode = 'cover',
	): array {
		$format = function_exists('imagewebp') ? 'webp' : 'png';
		$cacheKey = hash('sha256', implode('|', [
			'v2',
			(string)$file->getId(),
			$file->getEtag(),
			(string)$width,
			(string)$height,
			$text,
			(string)$opacity,
			$mode,
			$format,
		]));
		$folder = $this->cacheFolder();
		$filename = $cacheKey . '.' . $format;
		if ($folder->fileExists($filename)) {
			return [
				'content' => $folder->getFile($filename)->getContent(),
				'mimeType' => 'image/' . $format,
				'etag' => $cacheKey,
				'cached' => true,
			];
		}

		$preview = $this->preview->getPreview(
			$file,
			$width,
			$height,
			$mode === 'cover',
			$mode === 'cover' ? IPreview::MODE_COVER : IPreview::MODE_FILL,
		);
		$image = imagecreatefromstring($preview->getContent());
		if ($image === false) {
			throw new \InvalidArgumentException('Preview format cannot be watermarked');
		}
		$image = $this->resize($image, $width, $height, $mode);

		if ($text !== '') {
			imagealphablending($image, true);
			$alpha = 127 - (int)round(127 * ($opacity / 100));
			$shadow = imagecolorallocatealpha($image, 0, 0, 0, min(127, $alpha + 24));
			$foreground = imagecolorallocatealpha($image, 255, 255, 255, $alpha);
			$font = 5;
			$textWidth = imagefontwidth($font) * strlen($text);
			$textHeight = imagefontheight($font);
			$stepX = max(220, $textWidth + 100);
			$stepY = max(120, $textHeight + 80);

			for ($y = 38; $y < imagesy($image); $y += $stepY) {
				for ($x = (($y / $stepY) % 2) * (int)($stepX / 2) - 60; $x < imagesx($image); $x += $stepX) {
					imagestring($image, $font, (int)$x + 1, $y + 1, $text, $shadow);
					imagestring($image, $font, (int)$x, $y, $text, $foreground);
				}
			}
		}

		ob_start();
		if ($format === 'webp') imagewebp($image, null, 84);
		else imagepng($image, null, 7);
		$content = (string)ob_get_clean();
		imagedestroy($image);
		$folder->newFile($filename, $content);

		return ['content' => $content, 'mimeType' => 'image/' . $format, 'etag' => $cacheKey, 'cached' => false];
	}

	/** @param \GdImage $source */
	private function resize(\GdImage $source, int $width, int $height, string $mode): \GdImage {
		$sourceWidth = imagesx($source);
		$sourceHeight = imagesy($source);

		if ($mode === 'cover') {
			$scale = max($width / $sourceWidth, $height / $sourceHeight);
			$cropWidth = min($sourceWidth, (int)round($width / $scale));
			$cropHeight = min($sourceHeight, (int)round($height / $scale));
			$targetWidth = min($width, $sourceWidth);
			$targetHeight = min($height, $sourceHeight);
			$sourceX = max(0, (int)(($sourceWidth - $cropWidth) / 2));
			$sourceY = max(0, (int)(($sourceHeight - $cropHeight) / 2));
		} else {
			$scale = min(1, $width / $sourceWidth, $height / $sourceHeight);
			$targetWidth = max(1, (int)round($sourceWidth * $scale));
			$targetHeight = max(1, (int)round($sourceHeight * $scale));
			$cropWidth = $sourceWidth;
			$cropHeight = $sourceHeight;
			$sourceX = 0;
			$sourceY = 0;
		}

		if ($targetWidth === $sourceWidth && $targetHeight === $sourceHeight
			&& $cropWidth === $sourceWidth && $cropHeight === $sourceHeight) {
			return $source;
		}

		$target = imagecreatetruecolor($targetWidth, $targetHeight);
		imagealphablending($target, false);
		imagesavealpha($target, true);
		imagecopyresampled(
			$target,
			$source,
			0,
			0,
			$sourceX,
			$sourceY,
			$targetWidth,
			$targetHeight,
			$cropWidth,
			$cropHeight,
		);
		imagedestroy($source);
		return $target;
	}

	private function cacheFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder('watermarked-previews');
		} catch (\OCP\Files\NotFoundException) {
			return $this->appData->newFolder('watermarked-previews');
		}
	}
}
