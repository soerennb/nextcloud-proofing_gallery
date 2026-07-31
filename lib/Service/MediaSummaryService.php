<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

final class MediaSummaryService {
	private const SUPPORTED_VIDEO_MIMES = ['video/mp4', 'video/webm'];

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $clock,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{total: int, coverFileId: ?int, coverMimeType: ?string} */
	public function forFolder(int $galleryId, int $folderId, Folder $folder): array {
		$etag = $folder->getEtag();
		try {
			$cached = $this->find($galleryId);
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
			$this->store($galleryId, $folderId, $etag, $summary, $cached !== null);
		} catch (Throwable $exception) {
			// Cache persistence must never make a readable gallery unavailable.
			$this->logger->warning('Gallery summary cache could not be updated', ['exception' => $exception]);
		}
		return $summary;
	}

	public function invalidate(int $galleryId): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('proofing_summaries')
				->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		} catch (Throwable $exception) {
			// A stale row is harmless because folder ID and ETag are revalidated.
			$this->logger->warning('Gallery summary cache could not be invalidated', ['exception' => $exception]);
		}
	}

	/** @return array<string, mixed>|null */
	private function find(int $galleryId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('proofing_summaries')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
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

	/**
	 * @param array{total: int, coverFileId: ?int, coverMimeType: ?string} $summary
	 */
	private function store(int $galleryId, int $folderId, string $etag, array $summary, bool $exists): void {
		if ($exists) {
			$this->update($galleryId, $folderId, $etag, $summary);
			return;
		}

		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('proofing_summaries')->values($this->values($qb, $galleryId, $folderId, $etag, $summary))
				->executeStatement();
		} catch (UniqueConstraintViolationException) {
			$this->update($galleryId, $folderId, $etag, $summary);
		}
	}

	/** @param array{total: int, coverFileId: ?int, coverMimeType: ?string} $summary */
	private function update(int $galleryId, int $folderId, string $etag, array $summary): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_summaries')
			->set('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
			->set('folder_etag', $qb->createNamedParameter($etag))
			->set('media_total', $qb->createNamedParameter($summary['total'], IQueryBuilder::PARAM_INT))
			->set('cover_file_id', $qb->createNamedParameter($summary['coverFileId'], IQueryBuilder::PARAM_INT))
			->set('cover_mime_type', $qb->createNamedParameter($summary['coverMimeType']))
			->set('scanned_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @param array{total: int, coverFileId: ?int, coverMimeType: ?string} $summary
	 * @return array<string, mixed>
	 */
	private function values(IQueryBuilder $qb, int $galleryId, int $folderId, string $etag, array $summary): array {
		return [
			'gallery_id' => $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT),
			'folder_id' => $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT),
			'folder_etag' => $qb->createNamedParameter($etag),
			'media_total' => $qb->createNamedParameter($summary['total'], IQueryBuilder::PARAM_INT),
			'cover_file_id' => $qb->createNamedParameter($summary['coverFileId'], IQueryBuilder::PARAM_INT),
			'cover_mime_type' => $qb->createNamedParameter($summary['coverMimeType']),
			'scanned_at' => $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT),
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
		return str_starts_with($file->getMimeType(), 'image/')
			|| in_array($file->getMimeType(), self::SUPPORTED_VIDEO_MIMES, true);
	}
}
