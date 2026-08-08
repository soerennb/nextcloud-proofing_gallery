<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;

use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Db\GuestRating;
use OCA\ProofingGallery\Db\GuestRatingMapper;
use OCA\ProofingGallery\Db\PublicLink;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

final class GuestRatingService {
	public function __construct(
		private GuestRatingMapper $ratings,
		private ITimeFactory $clock,
		private CullingService $culling,
		private \OCP\IDBConnection $db,
		private GuestRatingAggregator $aggregator,
		private CapabilityPolicyService $capabilities,
	) {
	}

	public function save(PublicLink $link, Guest $guest, int $fileId, int $rating, string $pick = 'none'): GuestRating {
		$this->capabilities->assertFeature('guestRatings');
		if ($rating < 0 || $rating > 5 || !in_array($pick, ['none', 'pick', 'reject'], true)) {
			throw new \InvalidArgumentException('Invalid guest rating');
		}
		if ($guest->getGalleryId() !== $link->getGalleryId() || $link->getStatus() !== 'active') {
			throw new \InvalidArgumentException('Guest and public link do not belong to the same active gallery');
		}
		try {
			$value = $this->ratings->findGuestFile($link->getGalleryId(), $guest->getId(), $fileId);
			$value->setPublicLinkId($link->getId());
			$value->setRating($rating);
			$value->setPickState($pick);
			$value->setUpdatedAt($this->clock->getTime());
			return $this->ratings->update($value);
		} catch (DoesNotExistException) {
			$value = new GuestRating();
			$value->setGalleryId($link->getGalleryId());
			$value->setPublicLinkId($link->getId());
			$value->setGuestId($guest->getId());
			$value->setFileId($fileId);
			$value->setRating($rating);
			$value->setPickState($pick);
			$value->setUpdatedAt($this->clock->getTime());
			return $this->ratings->insert($value);
		}
	}

	/** @return list<GuestRating> */
	public function forGuest(Guest $guest): array {
		$this->capabilities->assertFeature('guestRatings');
		return $this->ratings->findForGuest($guest->getGalleryId(), $guest->getId());
	}

	/** @param list<int> $fileIds
	 * @return list<GuestRating>
	 */
	public function forGuestFiles(Guest $guest, array $fileIds): array {
		$this->capabilities->assertFeature('guestRatings');
		return $this->ratings->findForGuestFiles($guest->getGalleryId(), $guest->getId(), $fileIds);
	}

	/**
	 * @param list<int> $fileIds
	 * @return array{items: list<array<string, mixed>>, guests: array<int, string>}
	 */
	public function aggregate(\OCA\ProofingGallery\Db\Gallery $gallery, array $fileIds = []): array {
		$this->capabilities->assertFeature('guestRatings');
		$grouped = [];
		$guests = [];
		$values = $fileIds === [] ? $this->ratings->findForGallery($gallery->getId()) : $this->ratings->findForGalleryFiles($gallery->getId(), $fileIds);
		foreach ($values as $value) {
			$grouped[$value->getFileId()][] = $value;
			$guests[$value->getGuestId()] = '';
		}
		if ($guests !== []) {
			foreach (array_chunk(array_keys($guests), 500) as $guestIds) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('id', 'display_name')->from('proofing_guests')
					->where($qb->expr()->in('id', $qb->createNamedParameter($guestIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
				foreach (QueryResult::rows($qb->executeQuery()) as $row) $guests[(int)$row['id']] = (string)$row['display_name'];
			}
		}
		$items = [];
		foreach ($grouped as $fileId => $values) {
			$items[] = $this->aggregator->summarize((int)$fileId, $values, $guests);
		}
		return ['items' => $items, 'guests' => $guests];
	}

	/** @return array{items:list<array<string,mixed>>,total:int,nextCursor:?string} */
	public function aggregatePage(\OCA\ProofingGallery\Db\Gallery $gallery, int $limit, ?string $cursor, ScopedCursorCodec $cursors): array {
		$this->capabilities->assertFeature('guestRatings');
		$limit = max(1, min(100, $limit));
		$scope = 'guest-rating-results:' . $gallery->getId();
		$fileIds = $this->ratings->fileIdPage($gallery->getId(), $cursors->decode($cursor, $scope), $limit + 1);
		$hasMore = count($fileIds) > $limit;
		if ($hasMore) array_pop($fileIds);
		$items = $fileIds === [] ? [] : $this->aggregate($gallery, $fileIds)['items'];
		$last = $fileIds === [] ? null : $fileIds[array_key_last($fileIds)];
		return ['items' => $items, 'total' => $this->ratings->distinctFileCount($gallery->getId()), 'nextCursor' => $hasMore && $last !== null ? $cursors->encode($scope, $last) : null];
	}

	/**
	 * @param list<int> $fileIds
	 * @return list<array<string, mixed>>
	 */
	public function promotionPlan(\OCA\ProofingGallery\Db\Gallery $gallery, array $fileIds): array {
		$fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0)));
		if (count($fileIds) > 200) throw new \InvalidArgumentException('Select no more than 200 media items');
		$aggregates = array_column($this->aggregate($gallery, $fileIds)['items'], null, 'fileId');
		$current = $this->culling->forFiles($gallery->getOwnerUid(), $fileIds);
		$result = [];
		foreach (array_values(array_unique($fileIds)) as $fileId) {
			if (!isset($aggregates[$fileId])) continue;
			$aggregate = $aggregates[$fileId];
			$pick = $aggregate['picks']['pick'] > $aggregate['picks']['reject'] ? 'pick'
				: ($aggregate['picks']['reject'] > $aggregate['picks']['pick'] ? 'reject' : 'none');
			$owner = $current[$fileId] ?? null;
			$result[] = [
				'fileId' => $fileId,
				'guestUpdatedAt' => $aggregate['updatedAt'],
				'guestCount' => $aggregate['count'],
				'average' => $aggregate['average'],
				'target' => ['rating' => (int)round($aggregate['average']), 'pick' => $pick, 'color' => $owner?->getColor() ?? 'none'],
				'owner' => $owner?->jsonSerialize() ?? ['fileId' => $fileId, 'rating' => 0, 'pick' => 'none', 'color' => 'none', 'revision' => 0],
			];
		}
		return $result;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public function promote(string $ownerUid, \OCA\ProofingGallery\Db\Gallery $gallery, array $items): array {
		$plan = array_column($this->promotionPlan($gallery, array_map(static fn (array $item): int => (int)($item['fileId'] ?? 0), $items)), null, 'fileId');
		$current = $this->culling->forFiles($ownerUid, array_keys($plan));
		$updates = [];
		$unchanged = [];
		foreach ($items as $item) {
			$fileId = (int)($item['fileId'] ?? 0);
			$currentPlan = $plan[$fileId] ?? throw new \InvalidArgumentException('Guest rating promotion is stale');
			if ((int)($item['guestUpdatedAt'] ?? 0) !== $currentPlan['guestUpdatedAt']) throw new \InvalidArgumentException('Guest rating promotion is stale');
			$target = $currentPlan['target'];
			$owner = $current[$fileId] ?? null;
			if ($owner !== null && $owner->getRating() === (int)$target['rating'] && $owner->getPickState() === (string)$target['pick']) {
				$unchanged[$fileId] = $owner->jsonSerialize();
				continue;
			}
			$updates[] = [
				'fileId' => $fileId,
				'rating' => (int)$target['rating'],
				'pick' => (string)$target['pick'],
				'color' => (string)($target['color'] ?? $owner?->getColor() ?? 'none'),
				'expectedRevision' => (int)($item['expectedOwnerRevision'] ?? $owner?->getRevision() ?? 0),
			];
		}
		$changed = $updates === [] ? [] : array_column(array_map(static fn ($value): array => $value->jsonSerialize(), $this->culling->updateBatch($ownerUid, $gallery, $updates)), null, 'fileId');
		$result = [];
		foreach ($items as $item) {
			$fileId = (int)$item['fileId'];
			$result[] = $changed[$fileId] ?? $unchanged[$fileId];
		}
		return $result;
	}
}
