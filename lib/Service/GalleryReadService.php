<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\MediaSummaryRepository;
use OCA\ProofingGallery\Domain\GalleryPurpose;
use OCA\ProofingGallery\Dto\GallerySettings;

final class GalleryReadService {
	public function __construct(
		private FolderService $folders,
		private MediaSummaryService $summaries,
		private CollectionService $collections,
		private GalleryAccessService $access,
		private PolicyService $policies,
		private CapabilityPolicyService $capabilities,
		private GalleryCursorCodec $galleryCursors,
		private MediaSummaryRepository $summaryRows,
		private RetentionHandoffService $retention,
		private GalleryMediaCountService $mediaCounts,
		private \OCA\ProofingGallery\Db\GalleryMediaCountRepository $countRows,
	) {
	}

	/** @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function list(
		string $ownerUid,
		int $limit,
		int $offset,
		bool $archived,
		string $search,
		?string $sourceType = null,
		bool $ownedOnly = false,
	): array {
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$search = mb_substr(trim($search), 0, 120);
		if ($sourceType !== null && !in_array($sourceType, ['folder', 'collection'], true)) {
			throw new InvalidArgumentException('Unknown gallery source type');
		}

		$page = $this->access->page($ownerUid, $archived, $search, $sourceType, null, null, null, $ownedOnly, 'updated', null, $limit, $offset);
		return [
			'items' => array_map(fn (Gallery $gallery): array => $this->present($ownerUid, $gallery), $page['items']),
			'total' => $page['total'], 'limit' => $limit, 'offset' => $offset,
		];
	}

	/** @return array{items: list<array<string, mixed>>, total: int, nextCursor: ?string} */
	public function listV2(
		string $userUid,
		int $limit,
		?string $cursor,
		bool $archived,
		string $search,
		?string $sourceType,
		?string $status,
		?string $mode,
		?string $purpose,
		bool $ownedOnly,
		string $sort,
	): array {
		$limit = max(1, min(100, $limit));
		$search = mb_substr(trim($search), 0, 120);
		if ($sourceType !== null && !in_array($sourceType, ['folder', 'collection'], true)) throw new InvalidArgumentException('Unknown gallery source type');
		if ($status !== null && !in_array($status, ['draft', 'published', 'archived'], true)) throw new InvalidArgumentException('Unknown gallery status');
		if ($mode !== null && !in_array($mode, ['presentation', 'collaboration'], true)) throw new InvalidArgumentException('Unknown gallery mode');
		if ($purpose !== null && GalleryPurpose::tryFrom($purpose) === null) throw new InvalidArgumentException('Unknown gallery purpose');
		if (!in_array($sort, ['updated', 'created', 'title'], true)) throw new InvalidArgumentException('Unknown gallery sort');
		if ($archived && $status !== null && $status !== 'archived') throw new InvalidArgumentException('Status does not match archive view');
		$scope = compact('archived', 'search', 'sourceType', 'status', 'mode', 'purpose', 'ownedOnly');
		$decoded = $this->galleryCursors->decode($cursor, $sort, $scope);
		$page = $this->access->page($userUid, $archived, $search, $sourceType, $status, $mode, $purpose, $ownedOnly, $sort, $decoded, $limit + 1);
		$hasMore = count($page['items']) > $limit;
		$galleries = array_slice($page['items'], 0, $limit);
		$ids = array_map(static fn (Gallery $gallery): int => (int)$gallery->getId(), $galleries);
		$summaries = $this->summaryRows->findMany($ids);
		$countRows = $this->countRows->findMany($ids);
		$items = array_map(function (Gallery $gallery) use ($userUid, $page, $summaries, $countRows): array {
			$id = (int)$gallery->getId();
			$role = $gallery->getOwnerUid() === $userUid ? 'owner' : ($page['roles'][$id] ?? 'viewer');
			$summary = $summaries[$id] ?? null;
			$count = $countRows[$id] ?? null;
			if ($count === null || in_array($count['state'], ['pending', 'updating', 'error'], true)) $this->mediaCounts->queue($id);
			$presentation = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR))->presentation;
			return [
				'id' => $id,
				'title' => $gallery->getTitle(),
				'status' => $gallery->getStatus(),
				'mode' => $gallery->getMode(),
				'sourceType' => $gallery->getSourceType(),
				'deliveryMode' => $gallery->getDeliveryMode(),
				'purpose' => $gallery->getPurpose(),
				'workflowState' => $gallery->getWorkflowState(),
				'createdAt' => $gallery->getCreatedAt(),
				'updatedAt' => $gallery->getUpdatedAt(),
				'revision' => $gallery->getRevision(),
				'heroFileId' => $presentation->heroFileId,
				'coverFileId' => $presentation->coverFileId,
				'lifecycleNextAt' => $gallery->getLifecycleNextAt(),
				'mediaSummary' => [
					...GalleryMediaCountService::present($count),
					'coverFileId' => isset($summary['cover_file_id']) ? (int)$summary['cover_file_id'] : null,
					'coverMimeType' => $summary['cover_mime_type'] ?? null,
				],
				'permissions' => [
					'role' => $role,
					'canEdit' => $role === 'owner' || $role === 'editor',
					'canManageAccess' => $role === 'owner',
					'canArchive' => $role === 'owner',
				],
			];
		}, $galleries);
		$last = $galleries === [] ? null : $galleries[array_key_last($galleries)];
		$nextCursor = $hasMore && $last !== null ? $this->galleryCursors->encode(
			$sort,
			$sort === 'title' ? $last->getTitleSort() : ($sort === 'created' ? $last->getCreatedAt() : $last->getUpdatedAt()),
			(int)$last->getId(),
			$scope,
		) : null;
		return ['items' => $items, 'total' => $page['total'], 'nextCursor' => $nextCursor];
	}

	/** @return array<string, mixed> */
	public function present(string $userId, Gallery $gallery): array {
		$permissions = $this->access->permissions($userId, $gallery);
		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$effectiveCapabilities = $this->capabilities->effective($settings, $userId);
		$availableCapabilities = $this->capabilities->effective(userId: $userId);
		if ($gallery->getSourceType() === 'collection') {
			return [
				...$gallery->jsonSerialize(),
				'source' => $this->collections->sourceStatus($gallery),
				'mediaSummary' => [...$this->collections->summary($gallery), ...$this->mediaCounts->summary((int)$gallery->getId())],
				'permissions' => $permissions,
				'effectiveCapabilities' => $effectiveCapabilities,
				'availableCapabilities' => $availableCapabilities,
				'retention' => $this->retention->status($gallery),
			];
		}
		try {
			$folder = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
			$source = $this->folders->describeSource(
				$gallery->getOwnerUid(),
				$gallery->getFolderId(),
				$folder,
				$permissions['role'] === 'owner',
			);
			$source['type'] = 'folder';
			$mediaSummary = $this->summaries->forFolder(
				$gallery->getId(),
				$gallery->getFolderId(),
				$folder,
			);
			$mediaSummary = [...$mediaSummary, ...$this->mediaCounts->summary((int)$gallery->getId())];
		} catch (\OCA\ProofingGallery\Exception\FolderAccessException) {
			$source = [
					'type' => 'folder',
					'folderId' => $gallery->getFolderId(),
					'displayPath' => null,
					'state' => 'missing',
			];
			$mediaSummary = [
					...GalleryMediaCountService::present(['state' => 'unavailable']),
					'coverFileId' => null,
					'coverMimeType' => null,
			];
		}

		return [
			...$gallery->jsonSerialize(),
			'source' => $source,
			'mediaSummary' => $mediaSummary,
			'permissions' => $permissions,
			'effectiveCapabilities' => $effectiveCapabilities,
			'availableCapabilities' => $availableCapabilities,
			'retention' => $this->retention->status($gallery),
		];
	}

	/** @return array{sortBy: string, sortDirection: string} */
	public function instanceMediaSort(): array {
		$navigation = $this->policies->galleryDefaults()['navigation'];
		return ['sortBy' => $navigation['sortBy'], 'sortDirection' => $navigation['sortDirection']];
	}
}
