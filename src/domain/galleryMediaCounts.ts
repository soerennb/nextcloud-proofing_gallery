import { n, t } from '@nextcloud/l10n'
import type { Gallery } from '../types.ts'

export function galleryMediaCountLabel(summary: Gallery['mediaSummary']): string {
	if (summary.countState === 'unavailable') return t('proofing_gallery', 'Source unavailable')
	const { imageCount, videoCount } = summary
	const known = imageCount !== null && imageCount !== undefined && videoCount !== null && videoCount !== undefined
	const parts: string[] = []
	if (known) {
		if (imageCount > 0) parts.push(n('proofing_gallery', '%n image', '%n images', imageCount))
		if (videoCount > 0) parts.push(n('proofing_gallery', '%n video', '%n videos', videoCount))
		if (parts.length === 0) parts.push(t('proofing_gallery', 'No media'))
	}
	if (summary.countState === 'error') parts.push(t('proofing_gallery', 'Media count could not be updated'))
	else if (summary.countState !== 'ready') parts.push(known ? t('proofing_gallery', 'Updating media count…') : t('proofing_gallery', 'Counting media…'))
	return parts.join(' · ')
}
