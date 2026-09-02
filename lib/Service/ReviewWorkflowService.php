<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\CollaborationRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\Guest;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Db\ReviewRoundRepository;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Exception\ReviewConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

final class ReviewWorkflowService {
	use TTransactional;

	public function __construct(
		private ReviewRoundRepository $rounds,
		private PublicLinkMapper $links,
		private GalleryAccessService $access,
		private CollaborationRepository $collaboration,
		private ActivityService $activity,
		private IntegrationEventService $integrations,
		private ITimeFactory $clock,
		private IDBConnection $db,
	) {
	}

	/** @return array<string, mixed> */
	public function publicState(Gallery $gallery, PublicLink $link, ?Guest $guest = null): array {
		$rules = $this->rules($gallery, $link);
		$selection = $guest === null ? null : $this->collaboration->latestSelectionForLink((int)$gallery->getId(), (int)$link->getId(), (int)$guest->getId());
		return [
			'enabled' => $link->getReviewEnabled(),
			'dueDate' => $rules['dueDate'],
			'rules' => ['minimum' => $rules['minimum'], 'maximum' => $rules['maximum']],
			'progress' => $selection === null ? null : ['count' => (int)$selection['item_count'], 'status' => (string)$selection['status']],
			'current' => $link->getReviewEnabled() ? $this->present($this->ensure($link)) : null,
		];
	}

	/** @return array{items: list<array<string, mixed>>} */
	public function overview(string $userUid, int $galleryId): array {
		$gallery = $this->access->view($userUid, $galleryId);
		$items = [];
		foreach ($this->links->findForGallery($galleryId) as $link) {
			$current = $link->getReviewEnabled() ? $this->ensure($link) : null;
			$items[] = [
				'linkId' => (int)$link->getId(), 'name' => $link->getName(), 'linkStatus' => $link->getStatus(),
				'enabled' => $link->getReviewEnabled(), 'dueDate' => $this->rules($gallery, $link)['dueDate'],
				'rules' => $this->rules($gallery, $link),
				'current' => $current === null ? null : $this->present($current, true),
				'progress' => $current === null ? null : $this->ownerProgress($gallery, $link),
				'history' => $current === null ? [] : array_map(fn (array $row): array => $this->present($row, true), $this->rounds->history((int)$link->getId())),
			];
		}
		return ['items' => $items, 'canEdit' => $this->access->permissions($userUid, $gallery)['canEdit']];
	}

	public function synchronize(Gallery $gallery, PublicLink $link): void {
		if (!$link->getReviewEnabled()) return;
		$current = $this->ensure($link);
		$dueDate = $this->rules($gallery, $link)['dueDate'];
		if (($current['due_date'] ?? null) !== $dueDate) {
			$this->rounds->updateDueDate((int)$current['id'], $dueDate, $this->clock->getTime());
		}
	}

	/** @return array<string, mixed> */
	public function submit(Gallery $gallery, PublicLink $link, Guest $guest): array {
		$this->assertLink($gallery, $link);
		if (!$link->getReviewEnabled()) throw new \InvalidArgumentException('Review submission is disabled for this link');
		$current = $this->ensure($link);
		$rules = $this->rules($gallery, $link);
		$now = $this->clock->getTime();
		if ($rules['dueDate'] !== null && gmdate('Y-m-d', $now) > $rules['dueDate']) throw new \InvalidArgumentException('The selection deadline has passed');
		$selection = $this->collaboration->latestSelectionForLink((int)$gallery->getId(), (int)$link->getId(), (int)$guest->getId());
		if ($selection === null || $selection['status'] !== 'open') throw new \InvalidArgumentException('Save a selection draft before submitting');
		$count = (int)$selection['item_count'];
		if ($count < $rules['minimum']) throw new \InvalidArgumentException('Select at least ' . $rules['minimum'] . ' photos before submitting');
		if ($rules['maximum'] > 0 && $count > $rules['maximum']) throw new \InvalidArgumentException('Select no more than ' . $rules['maximum'] . ' photos before submitting');
		$this->atomic(function () use ($selection, $link, $current, $guest, $now): void {
			if (!$this->collaboration->submitSelection((int)$selection['id'], (int)$link->getId(), $now)
				|| !$this->rounds->submit((int)$current['id'], (int)$guest->getId(), $now)) {
				throw new ReviewConflictException('This review round is no longer open');
			}
		}, $this->db);
		$this->collaboration->markResponseReceived((int)$gallery->getId(), $now);
		$this->activity->record($gallery, $guest, 'review.submitted', ['publicLinkId' => (int)$link->getId(), 'round' => (int)$current['round_number']]);
		$this->integrations->emit('review.submitted', (int)$gallery->getId(), ['publicLinkId' => (int)$link->getId(), 'round' => (int)$current['round_number']]);
		return $this->publicState($gallery, $link, $guest);
	}

	/** @return array<string, mixed> */
	public function transition(string $userUid, int $galleryId, int $linkId, string $action): array {
		$gallery = $this->access->edit($userUid, $galleryId);
		$link = $this->owned($gallery, $linkId);
		if (!$link->getReviewEnabled()) throw new \InvalidArgumentException('Review workflow is disabled for this link');
		$current = $this->ensure($link);
		$now = $this->clock->getTime();
		try {
			$this->atomic(function () use ($action, $current, $gallery, $link, $now): void {
				$selection = $this->collaboration->latestSubmittedSelectionForLink((int)$gallery->getId(), (int)$link->getId());
				$changed = match ($action) {
					'approve' => $this->rounds->decide((int)$current['id'], 'submitted', 'approved', $now),
					'request-changes' => $this->rounds->reopen((int)$current['id'], 'submitted', $now),
					'reopen' => $this->rounds->reopen((int)$current['id'], 'approved', $now),
					default => throw new \InvalidArgumentException('Unknown review action'),
				};
				if (!$changed) throw new ReviewConflictException('The review state changed in another session');
				if ($selection === null) throw new ReviewConflictException('The submitted selection no longer exists');
				$from = $action === 'reopen' ? 'completed' : 'submitted';
				$to = $action === 'approve' ? 'completed' : 'open';
				if (!$this->collaboration->transitionSelection((int)$selection['id'], $from, $to, $now)) throw new ReviewConflictException('The selection state changed in another session');
			}, $this->db);
		} catch (ReviewConflictException|\InvalidArgumentException $exception) {
			throw $exception;
		} catch (\Throwable) {
			throw new ReviewConflictException('The review state changed in another session');
		}
		$type = match ($action) {
			'approve' => 'review.approved',
			'request-changes' => 'review.changes_requested',
			default => 'review.reopened',
		};
		$this->activity->record($gallery, null, $type, ['publicLinkId' => $linkId, 'round' => (int)$current['round_number']]);
		$this->integrations->emit($type, $galleryId, ['publicLinkId' => $linkId, 'round' => (int)$current['round_number']]);
		return $this->overview($userUid, $galleryId);
	}

	/** @return array<string, mixed> */
	private function ensure(PublicLink $link): array {
		$current = $this->rounds->current((int)$link->getId());
		if ($current !== null) return $current;
		try {
			$this->rounds->create($link->getGalleryId(), (int)$link->getId(), 1, $link->getReviewDueDate(), $this->clock->getTime());
		} catch (\Throwable) {
			// A concurrent request may have created the first round.
		}
		return $this->rounds->current((int)$link->getId()) ?? throw new \RuntimeException('Review round could not be created');
	}

	private function owned(Gallery $gallery, int $linkId): PublicLink {
		try { $link = $this->links->find($linkId); } catch (DoesNotExistException) { throw new \InvalidArgumentException('Public link not found'); }
		$this->assertLink($gallery, $link);
		return $link;
	}

	private function assertLink(Gallery $gallery, PublicLink $link): void {
		if ($link->getGalleryId() !== $gallery->getId() || $link->getStatus() !== 'active') throw new \InvalidArgumentException('Public link not found');
	}

	/** @return array{minimum: int, maximum: int, dueDate: ?string} */
	private function rules(Gallery $gallery, PublicLink $link): array {
		$defaults = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR))->review;
		return [
			'minimum' => $link->getReviewSelectionMin() ?? $defaults->selectionMinimum,
			'maximum' => $link->getReviewSelectionMax() ?? $defaults->selectionMaximum,
			'dueDate' => $link->getReviewDueDate() ?? $defaults->selectionDueDate,
		];
	}

	/** @return array{count: int, status: string}|null */
	private function ownerProgress(Gallery $gallery, PublicLink $link): ?array {
		$selection = $this->collaboration->latestSelectionForLinkOwner((int)$gallery->getId(), (int)$link->getId());
		return $selection === null ? null : ['count' => (int)$selection['item_count'], 'status' => (string)$selection['status']];
	}

	/** @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function present(array $row, bool $includeActor = false): array {
		$result = [
			'round' => (int)$row['round_number'], 'status' => (string)$row['status'],
			'dueDate' => $row['due_date'] === null ? null : (string)$row['due_date'],
			'submittedAt' => $row['submitted_at'] === null ? null : (int)$row['submitted_at'],
			'decidedAt' => $row['decided_at'] === null ? null : (int)$row['decided_at'],
			'updatedAt' => (int)$row['updated_at'],
		];
		if ($includeActor) $result['submittedBy'] = $row['submitted_by'] === null ? null : (string)$row['submitted_by'];
		return $result;
	}
}
