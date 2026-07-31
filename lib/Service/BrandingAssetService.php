<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Security\ISecureRandom;

final class BrandingAssetService {
	private const FOLDER = 'branding';
	private const MAX_BYTES = 5242880;

	public function __construct(private IAppData $appData, private ISecureRandom $random) {
	}

	/** @param array<string, mixed> $upload
	 * @return array{id: string, mimeType: string, size: int}
	 */
	public function store(array $upload): array {
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($upload['tmp_name'] ?? null)
			|| !is_file($upload['tmp_name'])) {
			throw new \InvalidArgumentException('A valid logo upload is required');
		}
		$content = file_get_contents($upload['tmp_name']);
		if ($content === false || $content === '' || strlen($content) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('The logo must be smaller than 5 MiB');
		}
		$mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
		$extension = match ($mimeType) {
			'image/png' => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/svg+xml', 'text/plain', 'text/xml', 'application/xml' => $this->safeSvg($content) ? 'svg' : null,
			default => null,
		};
		if ($extension === null) throw new \InvalidArgumentException('Use a PNG, JPEG, WebP or safe SVG logo');
		if ($extension === 'svg') $mimeType = 'image/svg+xml';
		$id = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC) . '.' . $extension;
		$folder = $this->folder();
		$folder->newFile($id, $content);
		return ['id' => $id, 'mimeType' => $mimeType, 'size' => strlen($content)];
	}

	public function get(string $id): ISimpleFile {
		if (preg_match('/^[A-Za-z0-9]{32}\.(png|jpg|webp|svg)$/', $id) !== 1) throw new NotFoundException('Branding asset not found');
		return $this->folder()->getFile($id);
	}

	public function delete(string $id): void {
		try {
			$this->get($id)->delete();
		} catch (NotFoundException) {
			// Removing an already missing asset is intentionally idempotent.
		}
	}

	public function mimeType(string $id): string {
		return str_ends_with($id, '.svg') ? 'image/svg+xml' : $this->get($id)->getMimeType();
	}

	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	private function safeSvg(string $content): bool {
		if (!str_contains(mb_strtolower($content), '<svg')) return false;
		return preg_match('/<!doctype|<!entity|<script|<style|<foreignobject|<iframe|<object|<embed|<image|<use|\sstyle\s*=|\son[a-z]+\s*=|@import|url\s*\(|(?:href|src)\s*=\s*["\']\s*(?:https?:|data:|\/\/)/i', $content) !== 1;
	}
}
