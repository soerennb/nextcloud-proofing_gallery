<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\DesignAsset;
use OCA\ProofingGallery\Db\DesignAssetMapper;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

final class DesignAssetService {
	private const MAX_BYTES = 5242880;
	private const MAX_PIXELS = 16000000;
	private const FOLDER = 'design-assets';

	public function __construct(
		private DesignAssetMapper $assets,
		private IAppData $appData,
		private ISecureRandom $random,
		private IDBConnection $db,
		private ITimeFactory $clock,
	) {
	}

	/** @param array<string, mixed> $upload */
	public function store(string $ownerUid, string $kind, array $upload): DesignAsset {
		if (!in_array($kind, ['logo', 'watermark'], true)) throw new \InvalidArgumentException('Unknown design asset kind');
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_file($upload['tmp_name'])) {
			throw new \InvalidArgumentException('A valid design asset upload is required');
		}
		$content = file_get_contents($upload['tmp_name']);
		if ($content === false || $content === '' || strlen($content) > self::MAX_BYTES) throw new \InvalidArgumentException('The asset must be smaller than 5 MiB');
		[$content, $mimeType, $extension, $width, $height] = $this->normalize($content, $kind);
		$publicId = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$key = $publicId . '.' . $extension;
		$this->folder()->newFile($key, $content);
		$asset = new DesignAsset();
		$asset->setPublicId($publicId); $asset->setOwnerUid($ownerUid); $asset->setKind($kind);
		$name = is_string($upload['name'] ?? null) ? basename(str_replace('\\', '/', $upload['name'])) : '';
		$asset->setName(mb_substr(trim($name) !== '' ? trim($name) : $kind, 0, 255));
		$asset->setStorageKey($key); $asset->setMimeType($mimeType); $asset->setSize(strlen($content));
		$asset->setWidth($width); $asset->setHeight($height); $asset->setCreatedAt($this->clock->getTime());
		try {
			return $this->assets->insert($asset);
		} catch (\Throwable $exception) {
			$this->folder()->getFile($key)->delete();
			throw $exception;
		}
	}

	/** @return list<DesignAsset> */
	public function listOwned(string $ownerUid, ?string $kind = null): array {
		if ($kind !== null && !in_array($kind, ['logo', 'watermark'], true)) throw new \InvalidArgumentException('Unknown design asset kind');
		return $this->assets->findAllOwned($ownerUid, $kind);
	}

	public function owned(string $ownerUid, string $publicId, ?string $kind = null): DesignAsset {
		if (preg_match('/^[A-Za-z0-9]{32}$/', $publicId) !== 1) throw new NotFoundException('Design asset not found');
		$asset = $this->assets->findOwned($publicId, $ownerUid);
		if ($kind !== null && $asset->getKind() !== $kind) throw new NotFoundException('Design asset not found');
		return $asset;
	}

	public function content(DesignAsset $asset): string {
		return $this->folder()->getFile($asset->getStorageKey())->getContent();
	}

	public function deleteOwned(string $ownerUid, string $publicId): void {
		$asset = $this->owned($ownerUid, $publicId);
		if ($this->references($ownerUid, $publicId) > 0) throw new \InvalidArgumentException('The design asset is still used by a gallery or preset');
		$this->assets->delete($asset);
		try { $this->folder()->getFile($asset->getStorageKey())->delete(); } catch (NotFoundException) {}
	}

	private function references(string $ownerUid, string $publicId): int {
		$total = 0;
		foreach ([['proofing_galleries', 'owner_uid'], ['proofing_presets', 'owner_uid']] as [$table, $ownerColumn]) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count())->from($table)
				->where($qb->expr()->eq($ownerColumn, $qb->createNamedParameter($ownerUid)))
				->andWhere($qb->expr()->like('settings', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($publicId) . '%')));
			$total += (int)$qb->executeQuery()->fetchOne();
		}
		return $total;
	}

	/** @return array{string, string, string, int, int} */
	private function normalize(string $content, string $kind): array {
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
		if ($kind === 'logo' && in_array($mime, ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'], true) && $this->safeSvg($content)) {
			return [$content, 'image/svg+xml', 'svg', 0, 0];
		}
		$info = @getimagesizefromstring($content);
		if ($info === false || $info[0] === 0 || $info[1] === 0 || $info[0] * $info[1] > self::MAX_PIXELS) {
			throw new \InvalidArgumentException('Use an image with at most 16 megapixels');
		}
		$image = @imagecreatefromstring($content);
		if (!$image instanceof \GdImage) throw new \InvalidArgumentException('The image could not be decoded');
		$extension = match ($info['mime']) { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => null };
		if ($extension === null) throw new \InvalidArgumentException('Use PNG, JPEG, WebP or a safe SVG logo');
		if ($kind === 'watermark') {
			$extension = 'png';
			$mime = 'image/png';
		} else {
			$mime = $info['mime'];
		}
		imagealphablending($image, false);
		imagesavealpha($image, true);
		ob_start();
		match ($extension) {
			'jpg' => imagejpeg($image, null, 90),
			'webp' => imagewebp($image, null, 90),
			default => imagepng($image, null, 7),
		};
		$normalized = (string)ob_get_clean();
		imagedestroy($image);
		return [$normalized, $mime, $extension, (int)$info[0], (int)$info[1]];
	}

	private function safeSvg(string $content): bool {
		if (!str_contains(mb_strtolower($content), '<svg')) return false;
		return preg_match('/<!doctype|<!entity|<\?xml-stylesheet|<script|<style|<foreignobject|<iframe|<object|<embed|<image|<use|\sstyle\s*=|\son[a-z]+\s*=|@import|url\s*\(|(?:href|src)\s*=/i', $content) !== 1;
	}

	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try { return $this->appData->getFolder(self::FOLDER); } catch (NotFoundException) { return $this->appData->newFolder(self::FOLDER); }
	}
}
