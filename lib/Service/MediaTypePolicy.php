<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\File;
use OCP\Files\Folder;

final class MediaTypePolicy {
	private const SUPPORTED = [
		'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif',
		'image/heic', 'image/heif', 'image/tiff', 'image/bmp',
		'video/mp4', 'video/webm', 'video/quicktime',
	];

	public function supports(File|string $media): bool {
		$mimeType = $media instanceof File ? $media->getMimeType() : $media;
		return in_array(strtolower(trim($mimeType)), self::SUPPORTED, true);
	}

	public function matches(string $declared, File|string $actual): bool {
		$actualMime = $actual instanceof File ? $actual->getMimeType() : $actual;
		return $this->supports($declared) && $this->supports($actualMime)
			&& strtolower(trim($declared)) === strtolower(trim($actualMime));
	}

	public static function visiblePath(string $path): bool {
		return !str_contains($path, "\0") && !preg_match('~(^|/)\.~', $path);
	}

	public function visibleFile(Folder $root, File $file): bool {
		$relative = ltrim(substr($file->getPath(), strlen(rtrim($root->getPath(), '/'))), '/');
		return $file->isReadable() && $root->isSubNode($file) && $this->supports($file) && self::visiblePath($relative);
	}
}
