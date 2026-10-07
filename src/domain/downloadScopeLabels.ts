import { t } from '@nextcloud/l10n'
import type { GallerySettings } from './gallerySettings.ts'

export function downloadScopeLabels(): Record<GallerySettings['delivery']['downloadScope'], string> {
	return {
		none: t('proofing_gallery', 'Downloads disabled'),
		individual: t('proofing_gallery', 'Individual files'),
		selection: t('proofing_gallery', 'Saved selections'),
		all: t('proofing_gallery', 'Files, selections, and entire gallery'),
	}
}
