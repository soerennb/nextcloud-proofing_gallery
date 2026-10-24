<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\GalleryAttentionRepository;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What waits for the photographer in each project: client results to review,
 * deadlines the client missed and links that are about to stop working.
 */
final class GalleryAttentionService {
	private const RECENT_WINDOW = 7 * 86400;
	private const EXPIRY_WINDOW = 7 * 86400;

	public function __construct(private GalleryAttentionRepository $rows, private ITimeFactory $time) {
	}

	/**
	 * @param list<int> $galleryIds
	 * @return array<int, array{openSelections: int, uploadsAwaitingReview: int, submittedReviews: int, overdueReviews: int, recentComments: int, expiringLinks: int, nextLinkExpiryAt: int|null, lastClientActivityAt: int|null, needsAttention: bool}>
	 */
	public function summaries(array $galleryIds): array {
		if ($galleryIds === []) return [];
		$now = $this->time->getTime();
		$today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
		$selections = $this->rows->openSelections($galleryIds);
		$uploads = $this->rows->uploadsAwaitingReview($galleryIds);
		$submitted = $this->rows->submittedReviews($galleryIds);
		$overdue = $this->rows->overdueReviews($galleryIds, $today);
		$comments = $this->rows->recentGuestComments($galleryIds, $now - self::RECENT_WINDOW);
		$feedback = $this->rows->guestFeedback($galleryIds);
		$expiring = $this->rows->expiringLinks($galleryIds, new \DateTimeImmutable('@' . $now), new \DateTimeImmutable('@' . ($now + self::EXPIRY_WINDOW)));
		$summaries = [];
		foreach ($galleryIds as $id) {
			$latest = max(
				$selections[$id]['latest'] ?? 0,
				$uploads[$id]['latest'] ?? 0,
				$submitted[$id]['latest'] ?? 0,
				$comments[$id]['latest'] ?? 0,
				$feedback[$id]['latest'] ?? 0,
			);
			$summary = [
				'openSelections' => $selections[$id]['count'] ?? 0,
				'uploadsAwaitingReview' => $uploads[$id]['count'] ?? 0,
				'submittedReviews' => $submitted[$id]['count'] ?? 0,
				'overdueReviews' => $overdue[$id]['count'] ?? 0,
				'recentComments' => $comments[$id]['count'] ?? 0,
				'expiringLinks' => $expiring[$id]['count'] ?? 0,
				'nextLinkExpiryAt' => isset($expiring[$id]) ? $expiring[$id]['latest'] : null,
				'lastClientActivityAt' => $latest > 0 ? $latest : null,
			];
			$summary['needsAttention'] = $summary['openSelections'] + $summary['uploadsAwaitingReview'] + $summary['submittedReviews']
				+ $summary['overdueReviews'] + $summary['expiringLinks'] > 0;
			$summaries[$id] = $summary;
		}
		return $summaries;
	}
}
