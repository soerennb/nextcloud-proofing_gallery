<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Search;

use OCA\ProofingGallery\Service\IntegrationReadService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

final class GallerySearchProvider implements IProvider {
	public function __construct(private IntegrationReadService $read, private IL10N $l10n, private IURLGenerator $urls) {
	}

	public function getId(): string {
		return 'proofing_gallery';
	}

	public function getName(): string {
		return $this->l10n->t('Customer galleries');
	}

	/** @param array<string, mixed> $routeParameters */
	public function getOrder(string $route, array $routeParameters): int {
		return 35;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = mb_substr(trim($query->getTerm()), 0, 120);
		if ($term === '') return SearchResult::complete($this->getName(), []);
		$page = $this->read->galleries($user->getUID(), $term, '', '', $query->getLimit(), is_string($query->getCursor()) ? $query->getCursor() : null);
		$entries = array_map(fn (array $gallery): SearchResultEntry => new SearchResultEntry(
			$this->urls->imagePath('proofing_gallery', 'app-dark.svg'),
			(string)$gallery['title'],
			$this->l10n->t('%1$s · %2$d photos', [(string)$gallery['workflowState'], (int)$gallery['mediaSummary']['total']]),
			(string)$gallery['internalUrl'],
		), $page['items']);
		return $page['nextCursor'] === null
			? SearchResult::complete($this->getName(), $entries)
			: SearchResult::paginated($this->getName(), $entries, $page['nextCursor']);
	}
}
