import { describe, expect, it } from 'vitest'

import { createDefaultGallerySettings } from './gallerySettings.ts'
import { applyGalleryTitleMode, galleryTitleMode } from './galleryTitlePresentation.ts'

describe('gallery title presentation', () => {
	it('maps persisted settings to the three Ionic title modes', () => {
		const presentation = createDefaultGallerySettings().presentation
		expect(galleryTitleMode(presentation)).toBe('large')

		presentation.titleSize = 'small'
		expect(galleryTitleMode(presentation)).toBe('compact')

		presentation.showTitle = false
		expect(galleryTitleMode(presentation)).toBe('hidden')
	})

	it('keeps the underlying settings compatible when switching modes', () => {
		const presentation = createDefaultGallerySettings().presentation
		applyGalleryTitleMode(presentation, 'compact')
		expect(presentation).toMatchObject({ showTitle: true, titleSize: 'small' })

		applyGalleryTitleMode(presentation, 'large')
		expect(presentation).toMatchObject({ showTitle: true, titleSize: 'medium' })

		applyGalleryTitleMode(presentation, 'hidden')
		expect(presentation.showTitle).toBe(false)
	})
})
