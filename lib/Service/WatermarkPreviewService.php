<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\Settings\PresentationSettings;
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
		private DesignAssetService $assets,
	) {
	}

	/** @return array{content: string, mimeType: string, etag: string, cached: bool} */
	public function render(
		File $file,
		int $width,
		int $height,
		PresentationSettings $settings,
		string $ownerUid,
		string $mode = 'cover',
	): array {
		$text = $settings->watermarkText;
		$opacity = $settings->watermarkOpacity;
		$imageAsset = null;
		if ($settings->watermarkImageAssetId !== null) {
			try {
				$imageAsset = $this->assets->owned($ownerUid, $settings->watermarkImageAssetId, 'watermark');
			} catch (\OCP\Files\NotFoundException|\OCP\AppFramework\Db\DoesNotExistException) {
				// A stale reference must not make every gallery preview fail.
			}
		}
		$imageChecksum = $imageAsset === null ? '' : hash('sha256', $this->assets->content($imageAsset));
		$format = function_exists('imagewebp') ? 'webp' : 'png';
		$cacheKey = hash('sha256', implode('|', [
			'v3',
			(string)$file->getId(),
			$file->getEtag(),
			(string)$width,
			(string)$height,
			$text,
			(string)$opacity,
			$settings->watermarkTextPosition,
			(string)$settings->watermarkTextSize,
			$imageChecksum,
			(string)$settings->watermarkImageOpacity,
			$settings->watermarkImagePosition,
			(string)$settings->watermarkImageScale,
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

		if ($imageAsset !== null) {
			$this->applyImage($image, $this->assets->content($imageAsset), $settings);
		}

		if ($text !== '') {
			imagealphablending($image, true);
			$alpha = 127 - (int)round(127 * ($opacity / 100));
			$this->applyText($image, $text, $settings->watermarkTextSize, $settings->watermarkTextPosition, $alpha);
		}

		ob_start();
		if ($format === 'webp') imagewebp($image, null, 84);
		else imagepng($image, null, 7);
		$content = (string)ob_get_clean();
		imagedestroy($image);
		$folder->newFile($filename, $content);

		return ['content' => $content, 'mimeType' => 'image/' . $format, 'etag' => $cacheKey, 'cached' => false];
	}

	private function applyText(\GdImage $image, string $text, int $size, string $position, int $alpha): void {
		$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
		if (!is_file($font) || !function_exists('imagettftext')) {
			imagestring($image, 5, 16, 16, $text, imagecolorallocatealpha($image, 255, 255, 255, $alpha));
			return;
		}
		$box = imagettfbbox($size, 0, $font, $text);
		if (!is_array($box)) return;
		$textWidth = abs($box[2] - $box[0]); $textHeight = abs($box[7] - $box[1]);
		$foreground = imagecolorallocatealpha($image, 255, 255, 255, $alpha);
		$shadow = imagecolorallocatealpha($image, 0, 0, 0, min(127, $alpha + 24));
		$points = $position === 'tile'
			? $this->tilePoints(imagesx($image), imagesy($image), $textWidth, $textHeight)
			: [$this->position(imagesx($image), imagesy($image), $textWidth, $textHeight, $position)];
		foreach ($points as [$x, $y]) {
			imagettftext($image, $size, 0, $x + 1, $y + 1, $shadow, $font, $text);
			imagettftext($image, $size, 0, $x, $y, $foreground, $font, $text);
		}
	}

	/** @return list<array{int, int}> */
	private function tilePoints(int $width, int $height, int $textWidth, int $textHeight): array {
		$points = []; $stepX = max(220, $textWidth + 100); $stepY = max(120, $textHeight + 80);
		for ($y = 38 + $textHeight; $y < $height; $y += $stepY) {
			for ($x = ((int)($y / $stepY) % 2) * (int)($stepX / 2) - 60; $x < $width; $x += $stepX) $points[] = [(int)$x, $y];
		}
		return $points;
	}

	private function applyImage(\GdImage $image, string $content, PresentationSettings $settings): void {
		$mark = @imagecreatefromstring($content);
		if (!$mark instanceof \GdImage) return;
		$targetWidth = max(1, (int)round(imagesx($image) * $settings->watermarkImageScale / 100));
		$targetHeight = max(1, (int)round(imagesy($mark) * $targetWidth / max(1, imagesx($mark))));
		$resized = imagescale($mark, $targetWidth, $targetHeight, IMG_BICUBIC_FIXED);
		imagedestroy($mark);
		if (!$resized instanceof \GdImage) return;
		[$x, $baseline] = $this->position(imagesx($image), imagesy($image), $targetWidth, $targetHeight, $settings->watermarkImagePosition);
		$y = $baseline - $targetHeight;
		$this->applyOpacity($resized, $settings->watermarkImageOpacity);
		imagealphablending($image, true);
		imagecopy($image, $resized, $x, $y, 0, 0, $targetWidth, $targetHeight);
		imagedestroy($resized);
	}

	private function applyOpacity(\GdImage $image, int $opacity): void {
		if ($opacity >= 100) return;
		imagealphablending($image, false);
		imagesavealpha($image, true);
		for ($y = 0; $y < imagesy($image); $y++) {
			for ($x = 0; $x < imagesx($image); $x++) {
				$rgba = imagecolorat($image, $x, $y);
				$sourceAlpha = ($rgba >> 24) & 0x7f;
				$alpha = 127 - (int)round((127 - $sourceAlpha) * ($opacity / 100));
				imagesetpixel($image, $x, $y, ($rgba & 0x00ffffff) | ($alpha << 24));
			}
		}
	}

	/** @return array{int, int} x and text baseline/bottom edge */
	private function position(int $width, int $height, int $itemWidth, int $itemHeight, string $position): array {
		$margin = max(12, (int)round(min($width, $height) * .03));
		$x = str_ends_with($position, 'right') ? $width - $itemWidth - $margin : (str_ends_with($position, 'left') ? $margin : (int)(($width - $itemWidth) / 2));
		$bottom = str_starts_with($position, 'top') ? $margin + $itemHeight : (str_starts_with($position, 'bottom') ? $height - $margin : (int)(($height + $itemHeight) / 2));
		return [max(0, $x), max($itemHeight, $bottom)];
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
