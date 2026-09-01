import { t } from '@nextcloud/l10n'

import type { MediaCull, UserPreferences } from '../types.ts'

export function defaultCullState(fileId: number): MediaCull {
	return { fileId, rating: 0, color: 'none', pick: 'none', source: 'app', revision: 0, sourceEtag: null, sidecarEtag: null, updatedAt: 0 }
}

export function effectiveCullingFilmstripPlacement(
	preference: UserPreferences['cullingFilmstripPlacement'],
	viewportWidth: number,
): 'side' | 'bottom' {
	if (preference === 'bottom') return 'bottom'
	if (preference === 'side') return viewportWidth >= 900 ? 'side' : 'bottom'
	return viewportWidth >= 1180 ? 'side' : 'bottom'
}

export const CULLING_COLORS: Array<{ value: MediaCull['color']; label: string }> = [
	{ value: 'none', label: t('proofing_gallery', 'No color') },
	{ value: 'red', label: t('proofing_gallery', 'Red') },
	{ value: 'yellow', label: t('proofing_gallery', 'Yellow') },
	{ value: 'green', label: t('proofing_gallery', 'Green') },
	{ value: 'blue', label: t('proofing_gallery', 'Blue') },
	{ value: 'purple', label: t('proofing_gallery', 'Purple') },
]
