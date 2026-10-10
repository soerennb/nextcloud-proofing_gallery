<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\MediaSummaryRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use Throwable;

final class MediaSummaryService {
	public function __construct(
		private MediaSummaryRepository $repository,
		private ITimeFactory $clock,
		private LoggerInterface $logger,
		private AutomaticCoverSelector $covers,
		private GalleryMediaCountInvalidator $counts,
	) {
	}

	/** @return array{total: int, coverFileId: ?int, coverMimeType: ?string} */
	public function forFolder(int $galleryId, int $folderId, Folder $folder): array {
		$etag = hash('sha256', 'cover-v2:' . $folder->getEtag());
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
			$this->counts->invalidate($galleryId);
		} catch (Throwable $exception) {
			// A stale row is harmless because folder ID and ETag are revalidated.
			$this->logger->warning('Gallery summary cache could not be invalidated', ['exception' => $exception]);
		}
	}

	/** @return array{total: int, coverFileId: ?int, coverMimeType: ?string} */
	private function scan(Folder $folder): array {
		$cover = $this->covers->find($folder);

		return [
			'total' => 0,
			'coverFileId' => $cover?->getId(),
			'coverMimeType' => $cover?->getMimeType(),
		];
	}

	/** @param array<string, mixed> $row
	 * @return array{total: int, coverFileId: ?int, coverMimeType: ?string}
	 */
	private function present(array $row): array {
		return [
			'total' => 0,
			'coverFileId' => $row['cover_file_id'] === null ? null : (int)$row['cover_file_id'],
			'coverMimeType' => $row['cover_mime_type'] === null ? null : (string)$row['cover_mime_type'],
		];
	}

}
