<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\CollaborationRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Domain\CollaborationReadScope;
use OCP\IURLGenerator;

final class IntegrationReadService {
	public function __construct(
		private GalleryAccessService $access,
		private GalleryService $galleries,
		private GalleryReadinessService $readiness,
		private CollaborationRepository $collaboration,
		private MediaIndexService $mediaIndex,
		private CollectionService $collections,
		private PresetService $presets,
		private PublicLinkMapper $publicLinks,
		private ReviewWorkflowService $reviews,
		private IURLGenerator $urls,
	) {
	}

	/** @return array{items: list<array<string, mixed>>, nextCursor: ?string} */
	public function galleries(string $userUid, string $query, string $status, string $purpose, int $limit, ?string $cursor): array {
		$limit = max(1, min(100, $limit));
		$archived = $status === 'archived';
		$page = $this->galleries->listV2(
			$userUid, $limit, $cursor, $archived, $query, null,
			$status === '' ? null : $status, null, $purpose === '' ? null : $purpose,
			false, 'updated',
		);
		return [
			'items' => array_map(fn (array $item): array => $this->gallery($userUid, $this->access->view($userUid, (int)$item['id'])), $page['items']),
			'nextCursor' => $page['nextCursor'],
		];
	}

	/** @return array<string, mixed> */
	public function galleryById(string $userUid, int $galleryId): array {
		return $this->gallery($userUid, $this->access->view($userUid, $galleryId));
	}

	/** @return array<string, mixed> */
	public function readiness(string $userUid, int $galleryId): array {
		$gallery = $this->access->view($userUid, $galleryId);
		return $this->readiness->evaluate($gallery, $userUid);
	}

	/** @return array<string, mixed> */
	public function feedback(string $userUid, int $galleryId): array {
		$gallery = $this->access->view($userUid, $galleryId);
		$state = $this->collaboration->state($gallery->getId(), CollaborationReadScope::all(), 0, allFiles: true);
		$comments = array_map(static fn (array $comment): array => [
			'fileId' => (int)$comment['file_id'],
			'body' => mb_substr(trim(strip_tags((string)$comment['body'])), 0, 2000),
			'createdAt' => gmdate(DATE_ATOM, (int)$comment['created_at']),
			'untrustedGuestContent' => true,
		], array_values(array_filter($state['comments'], static fn (array $comment): bool => $comment['deleted_at'] === null)));
		$selections = array_map(static fn (array $selection): array => [
			'id' => (string)$selection['public_id'],
			'name' => mb_substr(trim(strip_tags((string)$selection['name'])), 0, 120),
			'message' => mb_substr(trim(strip_tags((string)$selection['message'])), 0, 2000),
			'status' => (string)$selection['status'],
			'fileCount' => count($selection['fileIds']),
			'updatedAt' => gmdate(DATE_ATOM, (int)$selection['updated_at']),
			'untrustedGuestContent' => true,
		], $state['selections']);
		return [
			'galleryId' => $galleryId,
			'feedbackCount' => count($state['feedback']),
			'commentCount' => count($comments),
			'selectionCount' => count($selections),
			'comments' => $comments,
			'selections' => $selections,
			'reviews' => $this->reviews->overview($userUid, $galleryId),
		];
	}

	/** @return array<string, mixed> */
	public function reviews(string $userUid, int $galleryId): array {
		return $this->reviews->overview($userUid, $galleryId);
	}

	/** @return array<string, mixed> */
	public function media(string $userUid, int $galleryId, string $query, int $minRating, int $limit, ?string $cursor): array {
		$gallery = $this->access->view($userUid, $galleryId);
		if ($gallery->getSourceType() === 'collection') {
			$needle = mb_strtolower(trim($query));
			$items = array_values(array_filter($this->collections->availableItems($gallery), static fn (array $item): bool => $needle === '' || str_contains(mb_strtolower((string)$item['name']), $needle)));
			return ['items' => array_slice($items, 0, max(1, min(100, $limit))), 'nextCursor' => null, 'total' => count($items)];
		}
		return $this->mediaIndex->page($gallery, $limit, $cursor, '', $query, 'name', 'asc', $minRating);
	}

	/** @return list<array{id: int, name: string}> */
	public function presets(string $userUid): array {
		return array_map(static fn ($preset): array => ['id' => (int)$preset->getId(), 'name' => (string)$preset->getName()], $this->presets->list($userUid));
	}

	/** @return array<string, mixed> */
	private function gallery(string $userUid, Gallery $gallery): array {
		$presented = $this->galleries->present($userUid, $gallery);
		$links = array_map(static fn ($link): array => [
			'id' => (int)$link->getId(),
			'name' => (string)$link->getName(),
			'status' => (string)$link->getStatus(),
			'isPrimary' => (bool)$link->getIsPrimary(),
			'expiresAt' => null,
			'reviewEnabled' => (bool)$link->getReviewEnabled(),
			'reviewDueDate' => $link->getReviewDueDate(),
		], $this->publicLinks->findForGallery($gallery->getId()));
		return [
			'id' => (int)$gallery->getId(),
			'title' => (string)$gallery->getTitle(),
			'purpose' => (string)$gallery->getPurpose(),
			'status' => (string)$gallery->getStatus(),
			'workflowState' => (string)$gallery->getWorkflowState(),
			'sourceType' => (string)$gallery->getSourceType(),
			'revision' => (int)$gallery->getRevision(),
			'updatedAt' => gmdate(DATE_ATOM, (int)$gallery->getUpdatedAt()),
			'internalUrl' => $this->internalUrl($gallery->getId()),
			'source' => $presented['source'],
			'mediaSummary' => $presented['mediaSummary'],
			'permissions' => $presented['permissions'],
			'publicLinks' => $links,
		];
	}

	public function internalUrl(int $galleryId, string $tab = ''): string {
		$suffix = $tab === '' ? '' : '/' . rawurlencode($tab);
		return $this->urls->linkToRouteAbsolute('proofing_gallery.page.index') . '#gallery/' . $galleryId . $suffix;
	}

}
