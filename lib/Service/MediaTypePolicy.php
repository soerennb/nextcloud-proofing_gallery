<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\File;

final class MediaTypePolicy {
	private const VIDEO_MIME_TYPES = ['video/mp4', 'video/webm'];

	public function supports(File|string $media): bool {
		$mimeType = $media instanceof File ? $media->getMimeType() : $media;
		return str_starts_with($mimeType, 'image/') || in_array($mimeType, self::VIDEO_MIME_TYPES, true);
	}
}
