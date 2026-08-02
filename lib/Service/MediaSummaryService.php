<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\MediaSummaryRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Throwable;

final class MediaSummaryService {
	public function __construct(
		private MediaSummaryRepository $repository,
		private ITimeFactory $clock,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{total: int, coverFileId: ?int, coverMimeType: ?string} */
	public function forFolder(int $galleryId, int $folderId, Folder $folder): array {
		$etag = $folder->getEtag();
		try {
			$cached = $this->repository->find($galleryId);
		} catch (Throwable $exception) {
			$this->logger->warning('Gallery summary cache could not be read', ['exception' => $exception]);
			return $this->scan($folder);
		}
		if ($cached !== null
			&& (int)$cached['folder_id'] === $folderId
			&& hash_equals((string)$cached['folder_etag'], $etag)) {
			return $this->present($cached);
		}

		$summary = $this->scan($folder);
		try {
			$this->repository->upsert($galleryId, $folderId, $etag, $summary, $this->clock->getTime(), $cached !== null);
		} catch (Throwable $exception) {
			// Cache persistence must never make a readable gallery unavailable.
			$this->logger->warning('Gallery summary cache could not be updated', ['exception' => $exception]);
		}
		return $summary;
	}

	public function invalidate(int $galleryId): void {
		try {
			$this->repository->delete($galleryId);
		} catch (Throwable $exception) {
			// A stale row is harmless because folder ID and ETag are revalidated.
			$this->logger->warning('Gallery summary cache could not be invalidated', ['exception' => $exception]);
		}
	}

	/** @return array{total: int, coverFileId: ?int, coverMimeType: ?string} */
	private function scan(Folder $folder): array {
		$nodes = array_values(array_filter(
			$folder->getDirectoryListing(),
			fn (Node $node): bool => !str_starts_with($node->getName(), '.')
				&& ($node instanceof Folder || ($node instanceof File && $this->isSupported($node))),
		));
		usort($nodes, static fn (Node $left, Node $right): int => strnatcasecmp($left->getName(), $right->getName()));

		$cover = null;
		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			$cover ??= $node;
			if (str_starts_with($node->getMimeType(), 'image/')) {
				$cover = $node;
				break;
			}
		}

		return [
			'total' => count($nodes),
			'coverFileId' => $cover?->getId(),
			'coverMimeType' => $cover?->getMimeType(),
		];
	}

	/** @param array<string, mixed> $row
	 * @return array{total: int, coverFileId: ?int, coverMimeType: ?string}
	 */
	private function present(array $row): array {
		return [
			'total' => (int)$row['media_total'],
			'coverFileId' => $row['cover_file_id'] === null ? null : (int)$row['cover_file_id'],
			'coverMimeType' => $row['cover_mime_type'] === null ? null : (string)$row['cover_mime_type'],
		];
	}

	private function isSupported(File $file): bool {
		return str_starts_with($file->getMimeType(), 'image/') || str_starts_with($file->getMimeType(), 'video/');
	}
}
