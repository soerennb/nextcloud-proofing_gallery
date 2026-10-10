<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\IL10N;

final class GalleryMediaCountLabel {
	/** @param array<string, mixed> $summary */
	public static function format(IL10N $l10n, array $summary): string {
		if (($summary['countState'] ?? '') === 'unavailable') return $l10n->t('Source unavailable');
		$parts = [];
		$known = isset($summary['imageCount'], $summary['videoCount']);
		if ($known) {
			if ($summary['imageCount'] > 0) $parts[] = $l10n->n('%n image', '%n images', (int)$summary['imageCount']);
			if ($summary['videoCount'] > 0) $parts[] = $l10n->n('%n video', '%n videos', (int)$summary['videoCount']);
			if ($parts === []) $parts[] = $l10n->t('No media');
		}
		if (($summary['countState'] ?? '') === 'error') $parts[] = $l10n->t('Media count could not be updated');
		elseif (($summary['countState'] ?? '') !== 'ready') $parts[] = $known ? $l10n->t('Updating media count…') : $l10n->t('Counting media…');
		return implode(' · ', $parts);
	}
}
