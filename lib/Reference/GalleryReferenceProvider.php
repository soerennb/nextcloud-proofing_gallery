<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Reference;

use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\IntegrationReadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;

final class GalleryReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
	public function __construct(
		private IntegrationReadService $read,
		private IL10N $l10n,
		private IURLGenerator $urls,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return 'proofing_gallery';
	}

	public function getTitle(): string {
		return $this->l10n->t('Customer galleries');
	}

	public function getOrder(): int {
		return 35;
	}

	public function getIconUrl(): string {
		return $this->urls->imagePath('proofing_gallery', 'app-dark.svg');
	}

	public function getSupportedSearchProviderIds(): array {
		return ['proofing_gallery'];
	}

	public function matchReference(string $referenceText): bool {
		return $this->galleryId($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$galleryId = $this->galleryId($referenceText);
		if ($galleryId === null || $this->userId === null) return null;
		try {
			$gallery = $this->read->galleryById($this->userId, $galleryId);
		} catch (DoesNotExistException|AuthorizationException) {
			return null;
		}
		$reference = new Reference($referenceText);
		$reference->setTitle((string)$gallery['title']);
		$reference->setDescription($this->l10n->t('%1$s · %2$d photos', [(string)$gallery['workflowState'], (int)$gallery['mediaSummary']['total']]));
		$reference->setImageUrl($this->getIconUrl());
		$reference->setUrl((string)$gallery['internalUrl']);
		$reference->setRichObject('proofing_gallery', [
			'title' => $gallery['title'],
			'state' => $gallery['workflowState'],
			'photoCount' => $gallery['mediaSummary']['total'],
			'url' => $gallery['internalUrl'],
		]);
		return $reference;
	}

	public function getCachePrefix(string $referenceId): string {
		return 'proofing-gallery';
	}

	public function getCacheKey(string $referenceId): string {
		return ($this->userId ?? 'guest') . ':' . ($this->galleryId($referenceId) ?? 'invalid');
	}

	private function galleryId(string $referenceText): ?int {
		$base = preg_quote(rtrim($this->urls->linkToRouteAbsolute('proofing_gallery.page.index'), '/'), '/');
		if (preg_match('/^' . $base . '\/#gallery\/(\d+)(?:\/[^?#]*)?$/', $referenceText, $match) !== 1) return null;
		$id = (int)$match[1];
		return $id > 0 ? $id : null;
	}
}
