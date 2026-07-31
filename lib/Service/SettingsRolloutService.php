<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Exception\GalleryConflictException;
use OCP\AppFramework\Utility\ITimeFactory;

final class SettingsRolloutService {
	private const CATEGORIES = ['appearance', 'branding', 'review', 'downloads', 'lifecycle'];

	public function __construct(
		private GalleryMapper $galleries,
		private PolicyService $policies,
		private ITimeFactory $clock,
		private PublicShareService $shares,
	) {
	}

	/** @param list<int> $galleryIds
	 * @param list<string> $categories
	 * @return array<string, mixed>
	 */
	public function impact(array $galleryIds, array $categories): array {
		$this->validate($galleryIds, $categories);
		$defaults = $this->defaultsPatch($categories);
		$items = [];
		foreach ($this->galleries->findMany($galleryIds) as $gallery) {
			$current = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$updated = GallerySettings::merge($current, $defaults);
			$items[] = [
				'id' => $gallery->getId(),
				'title' => $gallery->getTitle(),
				'revision' => $gallery->getRevision(),
				'published' => $gallery->getShareToken() !== null,
				'changed' => $current->canonical() !== $updated->canonical(),
				'before' => $this->selected($current->canonical(), $categories),
				'after' => $this->selected($updated->canonical(), $categories),
			];
		}
		return ['categories' => $categories, 'items' => $items, 'count' => count($items)];
	}

	/** @param list<int> $galleryIds
	 * @param list<string> $categories
	 * @param array<int|string, int> $expectedRevisions
	 * @return array<string, mixed>
	 */
	public function apply(array $galleryIds, array $categories, array $expectedRevisions): array {
		$this->validate($galleryIds, $categories);
		$patch = $this->defaultsPatch($categories);
		$applied = [];
		$conflicts = [];
		foreach ($this->galleries->findMany($galleryIds) as $gallery) {
			$expected = (int)($expectedRevisions[$gallery->getId()] ?? $expectedRevisions[(string)$gallery->getId()] ?? 0);
			if ($expected <= 0 || $expected !== $gallery->getRevision()) {
				$conflicts[] = ['id' => $gallery->getId(), 'revision' => $gallery->getRevision()];
				continue;
			}
			$current = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
			$gallery->setSettings(json_encode(GallerySettings::merge($current, $patch), JSON_THROW_ON_ERROR));
			$gallery->setUpdatedAt($this->clock->getTime());
			try {
				$updated = $this->galleries->updateDocument($gallery, $expected);
				$applied[] = ['id' => $updated->getId(), 'revision' => $updated->getRevision()];
			} catch (GalleryConflictException) {
				$conflicts[] = ['id' => $gallery->getId(), 'revision' => $this->galleries->find($gallery->getId())->getRevision()];
			}
		}
		return ['applied' => $applied, 'conflicts' => $conflicts];
	}

	/** @param list<int> $galleryIds
	 * @return array<string, list<int>>
	 */
	public function revoke(array $galleryIds): array {
		if (count($galleryIds) > 100) throw new \InvalidArgumentException('At most 100 galleries can be changed at once');
		$revoked = [];
		$skipped = [];
		foreach ($this->galleries->findMany($galleryIds) as $gallery) {
			if ($gallery->getShareToken() === null) {
				$skipped[] = $gallery->getId();
				continue;
			}
			$this->shares->revoke($gallery);
			$revoked[] = $gallery->getId();
		}
		return compact('revoked', 'skipped');
	}

	/** @param list<int> $galleryIds
	 * @param list<string> $categories
	 */
	private function validate(array $galleryIds, array $categories): void {
		if ($galleryIds === [] || count($galleryIds) > 100) throw new \InvalidArgumentException('Select between 1 and 100 galleries');
		if ($categories === [] || array_diff($categories, self::CATEGORIES) !== []) throw new \InvalidArgumentException('Invalid settings category');
	}

	/** @param list<string> $categories
	 * @return array<string, mixed>
	 */
	private function defaultsPatch(array $categories): array {
		$defaults = $this->policies->galleryDefaults();
		$patch = [];
		if (in_array('appearance', $categories, true)) {
			$patch['publicLocale'] = $defaults['publicLocale'];
			$patch['presentation'] = $defaults['presentation'];
			$patch['navigation'] = $defaults['navigation'];
		}
		if (in_array('branding', $categories, true)) {
			$branding = $this->policies->instanceSettings()['branding'];
			$patch['presentation']['accentColor'] = $branding['accentColor'];
			$patch['presentation']['instanceLogoAssetId'] = $branding['logoAssetId'];
		}
		if (in_array('review', $categories, true)) {
			$patch['mode'] = $defaults['mode'];
			$patch['review'] = $defaults['review'];
			$patch['security'] = $defaults['security'];
		}
		if (in_array('downloads', $categories, true)) $patch['delivery'] = $defaults['delivery'];
		if (in_array('lifecycle', $categories, true)) $patch['lifecycle'] = $defaults['lifecycle'];
		return $patch;
	}

	/** @param array<string, mixed> $settings
	 * @param list<string> $categories
	 * @return array<string, mixed>
	 */
	private function selected(array $settings, array $categories): array {
		$selected = [];
		foreach ($this->defaultsPatch($categories) as $key => $_value) $selected[$key] = $settings[$key] ?? null;
		return $selected;
	}
}
