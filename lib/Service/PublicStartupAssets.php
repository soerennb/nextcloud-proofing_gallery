<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\IURLGenerator;

/** Uses the build's startup graph; no hashed chunk names live in PHP. */
final class PublicStartupAssets {
	public function __construct(private IURLGenerator $urls) {
	}

	/** @return list<array{rel: string, href: string}> */
	public function links(): array {
		$path = dirname(__DIR__, 2) . '/js/public-startup.json';
		if (!is_file($path)) return [];
		$json = file_get_contents($path);
		if ($json === false) throw new \RuntimeException('Public startup manifest could not be read');
		$assets = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		if (!is_array($assets)) throw new \RuntimeException('Public startup manifest must contain an asset list');
		$links = [];
		foreach ($assets as $asset) {
			if (!is_string($asset) || preg_match('~^js/[A-Za-z0-9_.-]+\.mjs$~', $asset) !== 1) throw new \RuntimeException('Invalid public startup asset');
			$links[] = ['rel' => 'modulepreload', 'href' => $this->urls->linkTo(Application::APP_ID, $asset)];
		}
		return $links;
	}
}
