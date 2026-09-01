import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from './gallerySettings.ts'
import { applyGalleryTitleMode, galleryTitleMode } from './galleryTitlePresentation.ts'

describe('gallery title presentation', () => {
	it('maps persisted settings to the three Ionic title modes', () => {
		const presentation = createDefaultGallerySettings().presentation
		expect(galleryTitleMode(presentation)).toBe('large')

		presentation.titleMode = 'compact'
		expect(galleryTitleMode(presentation)).toBe('compact')

		presentation.titleMode = 'hidden'
		expect(galleryTitleMode(presentation)).toBe('hidden')
	})

	it('keeps the underlying settings compatible when switching modes', () => {
		const presentation = createDefaultGallerySettings().presentation
		applyGalleryTitleMode(presentation, 'compact')
		expect(presentation).toMatchObject({ titleMode: 'compact', titleSize: 'medium' })

		applyGalleryTitleMode(presentation, 'large')
		expect(presentation).toMatchObject({ titleMode: 'large', titleSize: 'medium' })

		applyGalleryTitleMode(presentation, 'hidden')
		expect(presentation.titleMode).toBe('hidden')
	})
})
