import { t } from '@nextcloud/l10n'

import type { EventRecipient, EventWave } from '../services/eventApi.ts'

export function normalizeEventRecipientMatch(value: string | null | undefined): string {
	return (value ?? '').trim().toLocaleLowerCase()
}

export function eventRecipientStatusLabel(recipient: EventRecipient | null): string {
	if (!recipient) return t('proofing_gallery', 'Not released')
	if (recipient.status === 'published') return t('proofing_gallery', 'Link ready')
	if (recipient.status === 'invited') return t('proofing_gallery', 'Invited')
	if (recipient.status === 'failed') return t('proofing_gallery', 'Failed')
	if (recipient.status === 'revoked') return t('proofing_gallery', 'Revoked')
	return t('proofing_gallery', 'Draft')
}

export function eventWaveLabel(wave: EventWave): string {
	return ({
		draft: t('proofing_gallery', 'Draft'),
		scheduled: t('proofing_gallery', 'Scheduled'),
		releasing: t('proofing_gallery', 'Releasing'),
		released: t('proofing_gallery', 'Released'),
		partial_failed: t('proofing_gallery', 'Needs attention'),
		cancelled: t('proofing_gallery', 'Cancelled'),
	})[wave.status]
}

export function downloadEventBlob(blob: Blob, filename: string): void {
	const url = URL.createObjectURL(blob)
	const anchor = document.createElement('a')
	anchor.href = url
	anchor.download = filename
	anchor.click()
	URL.revokeObjectURL(url)
}
