import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-US',
	t: (_app: string, text: string, values: Record<string, string | number> = {}) => text.replace(/\{(\w+)\}/g, (_match, key: string) => String(values[key])),
	n: (_app: string, singular: string, plural: string, count: number) => (count === 1 ? singular : plural).replace('%n', String(count)),
}))

import { attentionItems, clientActivityLabel, galleryPhase } from './galleryPhase.ts'
import type { GalleryAttention } from '../types.ts'

const quiet: GalleryAttention = {
	openSelections: 0, uploadsAwaitingReview: 0, submittedReviews: 0, overdueReviews: 0,
	recentComments: 0, expiringLinks: 0, nextLinkExpiryAt: null, lastClientActivityAt: null, needsAttention: false,
}

describe('gallery phase', () => {
	it('derives one phase from publication and workflow state', () => {
		expect(galleryPhase({ status: 'draft', workflowState: 'preparing' })).toBe('draft')
		expect(galleryPhase({ status: 'published', workflowState: 'preparing' })).toBe('preparing')
		expect(galleryPhase({ status: 'published', workflowState: 'live' })).toBe('shared')
		expect(galleryPhase({ status: 'published', workflowState: 'response_received' })).toBe('responded')
		expect(galleryPhase({ status: 'published', workflowState: 'completed' })).toBe('completed')
		expect(galleryPhase({ status: 'archived', workflowState: 'response_received' })).toBe('archived')
	})
})

describe('attention items', () => {
	it('lists client work before housekeeping', () => {
		const items = attentionItems({ ...quiet, openSelections: 2, submittedReviews: 1, overdueReviews: 1, expiringLinks: 1, nextLinkExpiryAt: Date.UTC(2026, 9, 28, 12) / 1000 })
		expect(items.map(item => item.key)).toEqual(['reviews', 'selections', 'overdue', 'expiring'])
		expect(items[1]!.label).toBe('2 open selections')
		expect(items[3]).toMatchObject({ tone: 'warning', workspace: 'share', label: 'Link expires Oct 28, 2026' })
	})

	it('stays empty without open work', () => {
		expect(attentionItems(quiet)).toEqual([])
		expect(attentionItems(null)).toEqual([])
	})

	it('describes the latest client activity relatively', () => {
		const now = new Date('2026-10-24T12:00:00')
		expect(clientActivityLabel({ ...quiet, lastClientActivityAt: now.getTime() / 1000 - 7200 }, now)).toBe('Client active 2 hours ago')
		expect(clientActivityLabel(quiet, now)).toBe('')
	})
})
