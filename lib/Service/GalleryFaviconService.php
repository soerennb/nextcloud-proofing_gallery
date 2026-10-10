<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\IURLGenerator;

final class GalleryFaviconService {
	public function __construct(private IURLGenerator $urls) {
	}

	/** @return list<array<string, string>> */
	public function links(): array {
		return [
			['rel' => 'icon', 'type' => 'image/x-icon', 'sizes' => '16x16 32x32 48x48', 'href' => $this->asset('favicon.ico')],
			['rel' => 'icon', 'type' => 'image/svg+xml', 'sizes' => 'any', 'href' => $this->asset('favicon.svg')],
			['rel' => 'apple-touch-icon', 'sizes' => '180x180', 'href' => $this->asset('favicon-touch.png')],
			['rel' => 'mask-icon', 'color' => '#00679e', 'href' => $this->asset('favicon-mask.svg')],
		];
	}

	private function asset(string $name): string {
		$hash = hash_file('sha256', dirname(__DIR__, 2) . '/img/' . $name);
		if ($hash === false) throw new \RuntimeException('Gallery favicon asset is missing');
		// linkTo deliberately bypasses imagePath's Nextcloud theming substitution.
		return $this->urls->linkTo(Application::APP_ID, 'img/' . $name, ['v' => substr($hash, 0, 16)]);
	}
}
