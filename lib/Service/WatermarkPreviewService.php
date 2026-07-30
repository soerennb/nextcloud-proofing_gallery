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

	/** @return array{content: string, mimeType: string, etag: string} */
	public function render(File $file, int $width, int $height, string $text, int $opacity): array {
		$cacheKey = hash('sha256', implode('|', [
			(string)$file->getId(),
			$file->getEtag(),
			(string)$width,
			(string)$height,
			$text,
			(string)$opacity,
		]));
		$folder = $this->cacheFolder();
		$filename = $cacheKey . '.png';
		if ($folder->fileExists($filename)) {
			return [
				'content' => $folder->getFile($filename)->getContent(),
				'mimeType' => 'image/png',
				'etag' => $cacheKey,
			];
		}

		$preview = $this->preview->getPreview($file, $width, $height, true, IPreview::MODE_COVER);
		$image = imagecreatefromstring($preview->getContent());
		if ($image === false) {
			throw new \InvalidArgumentException('Preview format cannot be watermarked');
		}

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

		ob_start();
		imagepng($image, null, 7);
		$content = (string)ob_get_clean();
		imagedestroy($image);
		$folder->newFile($filename, $content);

		return ['content' => $content, 'mimeType' => 'image/png', 'etag' => $cacheKey];
	}

	private function cacheFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder('watermarked-previews');
		} catch (\OCP\Files\NotFoundException) {
			return $this->appData->newFolder('watermarked-previews');
		}
	}
}
