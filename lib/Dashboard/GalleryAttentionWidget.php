<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dashboard;

use OCA\ProofingGallery\Db\NativeNotificationRepository;
use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\IntegrationReadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;

final class GalleryAttentionWidget implements IAPIWidget, IIconWidget {
	public function __construct(
		private NativeNotificationRepository $notifications,
		private IntegrationReadService $read,
		private IL10N $l10n,
		private IURLGenerator $urls,
	) {
	}

	public function getId(): string { return 'proofing-gallery-attention'; }
	public function getTitle(): string { return $this->l10n->t('Gallery attention'); }
	public function getOrder(): int { return 35; }
	public function getIconClass(): string { return 'icon-picture'; }
	public function getIconUrl(): string { return $this->urls->getAbsoluteURL($this->urls->imagePath('proofing_gallery', 'app-dark.svg')); }
	public function getUrl(): string { return $this->urls->linkToRouteAbsolute('proofing_gallery.page.index'); }
	public function load(): void { }

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$items = [];
		foreach ($this->notifications->activeForUser($userId, min(50, $limit * 3)) as $state) {
			if ($since !== null && (int)$state['id'] >= (int)$since) continue;
			try {
				$gallery = $this->read->galleryById($userId, (int)$state['gallery_id']);
			} catch (DoesNotExistException|AuthorizationException) {
				continue;
			}
			$items[] = new WidgetItem(
				(string)$gallery['title'],
				$this->subtitle((string)$state['category'], (int)$state['event_count']),
				(string)$gallery['internalUrl'],
				$this->getIconUrl(),
				(string)$state['id'],
			);
			if (count($items) >= $limit) break;
		}
		return $items;
	}

	private function subtitle(string $category, int $count): string {
		$label = match ($category) {
			'comment' => $this->l10n->t('new comments'),
			'selection' => $this->l10n->t('new selections'),
			'upload' => $this->l10n->t('new uploads'),
			'manager' => $this->l10n->t('access changed'),
			'lifecycle' => $this->l10n->t('lifecycle update'),
			default => $this->l10n->t('gallery update'),
		};
		return $this->l10n->t('%1$d %2$s need attention', [$count, $label]);
	}
}
