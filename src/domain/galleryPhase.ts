import { n, t } from '@nextcloud/l10n'

import type { Gallery, GalleryAttention } from '../types.ts'
import { formatDate, formatRelative } from './dateFormat.ts'

export type GalleryPhase = 'draft' | 'preparing' | 'shared' | 'responded' | 'completed' | 'archived'

export interface AttentionItem {
	key: 'selections' | 'uploads' | 'reviews' | 'overdue' | 'expiring'
	label: string
	tone: 'action' | 'warning'
	workspace: 'review' | 'share'
}

type PhaseSource = Pick<Gallery, 'status' | 'workflowState'>

// One project phase derived from publication status and the server-maintained workflow state.
export function galleryPhase(gallery: PhaseSource): GalleryPhase {
	if (gallery.status === 'archived') return 'archived'
	if (gallery.workflowState === 'completed') return 'completed'
	if (gallery.status === 'draft') return 'draft'
	if (gallery.workflowState === 'response_received') return 'responded'
	if (gallery.workflowState === 'live') return 'shared'
	return 'preparing'
}

export function galleryPhaseLabel(phase: GalleryPhase): string {
	return {
		draft: t('proofing_gallery', 'Draft'),
		preparing: t('proofing_gallery', 'Preparing'),
		shared: t('proofing_gallery', 'With client'),
		responded: t('proofing_gallery', 'Client responded'),
		completed: t('proofing_gallery', 'Completed'),
		archived: t('proofing_gallery', 'Archived'),
	}[phase]
}

// Ordered by urgency: things the client is waiting for first, then housekeeping.
export function attentionItems(attention: GalleryAttention | null | undefined): AttentionItem[] {
	if (!attention) return []
	const items: AttentionItem[] = []
	if (attention.submittedReviews > 0) items.push({ key: 'reviews', tone: 'action', workspace: 'review', label: n('proofing_gallery', '%n review awaits your decision', '%n reviews await your decision', attention.submittedReviews) })
	if (attention.openSelections > 0) items.push({ key: 'selections', tone: 'action', workspace: 'review', label: n('proofing_gallery', '%n open selection', '%n open selections', attention.openSelections) })
	if (attention.uploadsAwaitingReview > 0) items.push({ key: 'uploads', tone: 'action', workspace: 'review', label: n('proofing_gallery', '%n upload to approve', '%n uploads to approve', attention.uploadsAwaitingReview) })
	if (attention.overdueReviews > 0) items.push({ key: 'overdue', tone: 'warning', workspace: 'review', label: n('proofing_gallery', '%n review is overdue', '%n reviews are overdue', attention.overdueReviews) })
	if (attention.expiringLinks > 0) {
		items.push({
			key: 'expiring',
			tone: 'warning',
			workspace: 'share',
			label: attention.nextLinkExpiryAt
				? t('proofing_gallery', 'Link expires {date}', { date: formatDate(attention.nextLinkExpiryAt) })
				: n('proofing_gallery', '%n link expires soon', '%n links expire soon', attention.expiringLinks),
		})
	}
	return items
}

export function clientActivityLabel(attention: GalleryAttention | null | undefined, now = new Date()): string {
	if (!attention?.lastClientActivityAt) return ''
	return t('proofing_gallery', 'Client active {time}', { time: formatRelative(attention.lastClientActivityAt, now) })
}
