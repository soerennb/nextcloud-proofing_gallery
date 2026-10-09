import { describe, expect, it } from 'vitest'
import { createDefaultGallerySettings } from './gallerySettings.ts'
import { feedbackBlock, feedbackFeatures, inheritedFeedback } from './feedbackPermissions.ts'
import type { EffectiveCapabilities } from '../types.ts'

function available(): EffectiveCapabilities {
	return Object.fromEntries(['likes', 'colors', 'comments', 'annotations', 'selections', 'guestRatings'].map(feature => [feature, { allowed: true, reason: null }])) as EffectiveCapabilities
}

describe('feedback restrictions', () => {
	it('keeps disabled gallery switches usable despite their effective capability being false', () => {
		const settings = createDefaultGallerySettings()
		settings.mode = 'collaboration'
		for (const feature of feedbackFeatures) {
			settings.review[feature] = false
			expect(feedbackBlock(feature, settings, available())).toBe(feature === 'annotations' ? 'comments' : null)
		}
	})

	it('distinguishes administrator, mode, gallery and link restrictions', () => {
		const settings = createDefaultGallerySettings()
		const capabilities = available()
		const link = { ...settings.review, ratings: true }
		capabilities.guestRatings.allowed = false
		expect(feedbackBlock('ratings', settings, capabilities, link)).toBe('administrator')
		capabilities.guestRatings.allowed = true
		expect(feedbackBlock('ratings', settings, capabilities, link)).toBe('mode')
		settings.mode = 'collaboration'
		expect(feedbackBlock('ratings', settings, capabilities, link)).toBe('gallery')
		settings.review.ratings = true
		link.ratings = false
		expect(feedbackBlock('ratings', settings, capabilities, link)).toBe('link')
		link.ratings = true
		expect(feedbackBlock('ratings', settings, capabilities, link)).toBeNull()
	})

	it('inherits configured feedback while respecting the comment dependency', () => {
		const settings = createDefaultGallerySettings()
		settings.review.comments = false
		settings.review.ratings = true
		expect(inheritedFeedback(settings)).toMatchObject({ annotations: false, ratings: true })
		expect(settings.review.annotations).toBe(true)
	})
})
