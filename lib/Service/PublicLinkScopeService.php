<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\Files\NotFoundException;

final class PublicLinkScopeService {
	public function normalize(string $path): string {
		$path = trim($path, '/');
		if (str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
			throw new NotFoundException('Invalid gallery path');
		}
		return $path;
	}

	public function indexPath(PublicLink $link, string $guestPath): string {
		return implode('/', array_filter([
			$this->normalize($link->getStartPath()),
			$this->normalize($guestPath),
		], static fn (string $part): bool => $part !== ''));
	}

	public function contains(PublicLink $link, GallerySettings $settings, string $relativeFilePath): bool {
		try {
			$startPath = $this->normalize($link->getStartPath());
			$relativeFilePath = $this->normalize($relativeFilePath);
		} catch (NotFoundException) {
			return false;
		}
		if ($startPath !== '' && $relativeFilePath !== $startPath && !str_starts_with($relativeFilePath, $startPath . '/')) return false;
		if ($link->getViewMode() === 'recursive' || $settings->navigation->folders) return true;
		return trim(dirname($relativeFilePath), './') === $startPath;
	}
}
