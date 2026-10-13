<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Dto\PublicGalleryQuery;
use OCA\ProofingGallery\Domain\MediaSort;

final class PublicGalleryDataService {
	public function __construct(private CollectionPublicPageReader $collections, private IndexedPublicPageReader $indexed, private FolderPublicPageReader $folders, private PublicGalleryPresenter $presenter) {}

	/** @return array<string, mixed> */
	public function page(PublicShareContext $context, PublicGalleryQuery $query = new PublicGalleryQuery()): array {
		$query = $this->resolveQuery($context, $query);
		$reader = match (true) {
			$context->gallery->getSourceType() === 'collection' => $this->collections,
			$context->link->getViewMode() === 'recursive' => $this->indexed,
			default => $this->folders,
		};
		return $this->presenter->present($context, $reader->read($context, $query));
	}

	private function resolveQuery(PublicShareContext $context, PublicGalleryQuery $query): PublicGalleryQuery {
		$gallery = $context->gallery;
		$limit = $query->limit;
		$offset = $query->offset;
		$path = $query->path;
		$search = $query->search;
		$sortBy = $query->sortBy;
		$sortDirection = $query->sortDirection;
		$groupBy = $query->groupBy;
		$cursor = $query->cursor;
		$pageNumber = $query->page;
		$focusId = $query->focusId;
		$limit = max(1, min(200, $limit));
		$offset = $pageNumber === null ? max(0, $offset) : (max(1, $pageNumber) - 1) * $limit;
		$path = trim($path, '/');
		if (!MediaTypePolicy::visiblePath($path)) throw new \OCP\Files\NotFoundException('Gallery folder not found');
		$search = mb_substr(trim($search), 0, 120);
		$settings = $context->settings;
		$sortBy = $sortBy !== '' ? $sortBy : $settings->navigation->sortBy;
		$sortDirection = $sortDirection !== '' ? $sortDirection : $settings->navigation->sortDirection;
		$groupBy = $groupBy !== '' ? $groupBy : $settings->navigation->groupBy;
		if (!in_array($sortBy, $gallery->getSourceType() === 'collection' ? MediaSort::ALL : MediaSort::AUTOMATIC, true)
			|| !in_array($sortDirection, ['asc', 'desc'], true)
			|| !in_array($groupBy, ['none', 'type', 'folder'], true)) {
			throw new \InvalidArgumentException('Invalid gallery arrangement');
		}
		if ($gallery->getSourceType() === 'collection') {
			if ($path !== '') throw new \OCP\Files\NotFoundException('Collections do not contain folders');
			$groupBy = 'none';
			if ($sortBy === 'collection') $sortDirection = 'asc';
		} else {
			if (!$context->scope->multiRoot && !$settings->navigation->folders && $path !== '') throw new \OCP\Files\NotFoundException('Folder navigation is disabled');
			if ($context->scope->multiRoot && !$context->scope->visiblePath($path)) throw new \OCP\Files\NotFoundException('Gallery folder not found');
		}
		return new PublicGalleryQuery($limit, $offset, $path, $search, $sortBy, $sortDirection, $groupBy, $cursor, $pageNumber, $focusId);
	}
}
